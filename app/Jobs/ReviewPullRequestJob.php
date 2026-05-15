<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Concerns\WorkspaceContext;
use App\Enums\CommentType;
use App\Enums\LlmCallRole;
use App\Enums\ReviewStatus;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\Workspace;
use App\Queue\BindWorkspaceMiddleware;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\LlmReviewException;
use App\Services\Llm\PromptBuilder;
use App\Services\Review\CommentPoster;
use App\Services\Review\CostReservationInterface;
use App\Services\Review\DiffChunker;
use App\Services\Review\SecretRedactor;
use App\Services\Review\SkipRules;
use App\Support\RetryDecision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ReviewPullRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    private const COST_RESERVATION_TOKENS = 10_000;

    private const ERROR_COMMENT_MARKER = '🤖 cdv-rabbit (AI generated):';

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $repositoryId,
        public readonly int $pullRequestNumber,
        public readonly string $headSha,
    ) {
        $this->onQueue('reviews');
    }

    public function handle(
        BitbucketClient $bitbucket,
        LlmDriverInterface $llm,
        CostReservationInterface $costReservation,
        DiffChunker $chunker,
        SecretRedactor $redactor,
        SkipRules $skipRules,
        PromptBuilder $promptBuilder,
        CommentPoster $commentPoster,
    ): void {
        $workspace = Workspace::find($this->workspaceId);
        $repository = Repository::find($this->repositoryId);

        if ($workspace === null || $repository === null) {
            Log::warning('ReviewPullRequestJob: workspace or repository not found', [
                'workspace_id' => $this->workspaceId,
                'repository_id' => $this->repositoryId,
            ]);

            return;
        }

        $repoFullSlug = $repository->full_slug;

        $review = Review::firstOrCreate(
            [
                'workspace_id' => $this->workspaceId,
                'repository_id' => $this->repositoryId,
                'pull_request_number' => $this->pullRequestNumber,
                'head_sha' => $this->headSha,
            ],
            [
                'correlation_id' => Str::uuid()->toString(),
                'base_sha' => '',
                'status' => ReviewStatus::Queued,
                'started_at' => null,
                'finished_at' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_usd_cents' => 0,
                'secrets_redacted' => 0,
            ]
        );

        // AC8: kill switch — abort before any external call (per-workspace or global operator flag)
        if ($workspace->kill_switch_enabled || config('cdv-rabbit.killed')) {
            $review->update(['status' => ReviewStatus::Skipped, 'finished_at' => now()]);
            Log::info('ReviewPullRequestJob: kill switch enabled, skipping', ['review_id' => $review->id]);
            $this->emitReviewLog($review->fresh());

            return;
        }

        // AC11 / M1: atomic cost reservation — Redis Lua script prevents TOCTOU
        $dailyCap = $costReservation->dailyCapFor($workspace);
        $reservation = $costReservation->reserve($this->workspaceId, self::COST_RESERVATION_TOKENS, $dailyCap);

        if ($reservation->denied()) {
            $review->update([
                'status' => ReviewStatus::Failed,
                'error_class' => 'cost_ceiling',
                'error_message' => "Daily token cap of {$dailyCap} exceeded.",
                'finished_at' => now(),
            ]);

            Log::warning('ReviewPullRequestJob: daily cost ceiling exceeded', [
                'review_id' => $review->id,
                'workspace_id' => $this->workspaceId,
                'consumed' => $reservation->consumed,
                'cap' => $dailyCap,
            ]);

            $costReservation->notifyIfThresholdExceeded($workspace, $reservation->consumed);
            $this->emitReviewLog($review->fresh());

            return;
        }

        $review->update(['status' => ReviewStatus::Running, 'started_at' => now()]);

        try {
            // Pre-flight: verify PR is still OPEN and refresh head_sha
            $prData = $bitbucket->getPullRequest($repoFullSlug, $this->pullRequestNumber);

            if ($prData === null || ($prData['state'] ?? '') !== 'OPEN') {
                $review->update(['status' => ReviewStatus::Skipped, 'finished_at' => now()]);
                $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);
                Log::info('ReviewPullRequestJob: PR not open, skipping', ['review_id' => $review->id]);
                $this->emitReviewLog($review->fresh());

                return;
            }

            $currentHeadSha = $prData['source']['commit']['hash'] ?? $this->headSha;
            if ($currentHeadSha !== $this->headSha) {
                Log::info('ReviewPullRequestJob: head_sha changed since dispatch, continuing with new sha', [
                    'review_id' => $review->id,
                    'old_sha' => $this->headSha,
                    'new_sha' => $currentHeadSha,
                ]);
                $review->update(['head_sha' => $currentHeadSha]);
            }

            $prMetadata = [
                'title' => $prData['title'] ?? '',
                'branch' => $prData['source']['branch']['name'] ?? '',
                'author' => $prData['author']['display_name'] ?? '',
            ];

            // Pre-flight: diffstat size check (AC: diff too large)
            $rawDiffStat = $bitbucket->getDiffStat($repoFullSlug, $this->pullRequestNumber);
            $diffStat = $this->aggregateDiffStat($rawDiffStat);

            if ($skipRules->isPrTooLarge($diffStat)) {
                $this->postSkippedSummary($bitbucket, $repoFullSlug, $review, 'This PR is too large to review automatically (> 8 000 changed lines).');
                $review->update(['status' => ReviewStatus::Skipped, 'finished_at' => now()]);
                $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);
                $this->emitReviewLog($review->fresh());

                return;
            }

            // LGPD: diff is a LOCAL variable — never assigned to $this, never logged, never persisted
            $diff = $bitbucket->getDiff($repoFullSlug, $this->pullRequestNumber);

            if ($diff === null || trim($diff) === '') {
                $review->update(['status' => ReviewStatus::Skipped, 'finished_at' => now()]);
                $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);
                $this->emitReviewLog($review->fresh());

                return;
            }

            // Chunk and apply file-level skip rules
            $fileDiffs = [];
            foreach ($chunker->chunk($diff) as $fileDiff) {
                if ($skipRules->isFileExcluded($fileDiff->path)) {
                    continue;
                }
                if ($skipRules->isFileTooLarge($fileDiff->hunks)) {
                    continue;
                }
                $fileDiffs[] = $fileDiff;
            }

            unset($diff); // Release local diff memory as soon as chunking is done

            if (count($fileDiffs) === 0) {
                $review->update(['status' => ReviewStatus::Skipped, 'finished_at' => now()]);
                $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);
                $this->emitReviewLog($review->fresh());

                return;
            }

            // Pre-LLM secret redaction (AC9)
            $totalSecretsRedacted = 0;
            $allComments = [];
            $totalInputTokens = 0;
            $totalOutputTokens = 0;

            $systemPrompt = $llm->getSystemPrompt();
            $toolSchema = $llm->getToolSchema();

            foreach ($fileDiffs as $fileDiff) {
                $redactionResult = $redactor->redact($fileDiff->hunks);
                $totalSecretsRedacted += $redactionResult->count;

                $userMessage = $promptBuilder->wrap($redactionResult->sanitized, $prMetadata);

                $result = $llm->reviewDiff($systemPrompt, $toolSchema, $userMessage);

                // Telemetry per file call
                app(LlmCallTelemetry::class)->record(
                    review: $review,
                    modelId: config('cdv-rabbit.models.review', 'claude-sonnet-4-6'),
                    role: LlmCallRole::Review,
                    result: $result,
                );

                $totalInputTokens += $result->inputTokens + $result->cacheReadInputTokens + $result->cacheCreationInputTokens;
                $totalOutputTokens += $result->outputTokens;
                $allComments = array_merge($allComments, $result->comments);
            }

            // Build aggregated ReviewResultDto for CommentPoster
            $aggregatedResult = $this->buildAggregatedResult($allComments, $totalInputTokens, $totalOutputTokens);

            // AC3: post comments to Bitbucket
            $commentPoster->post($review, $aggregatedResult, $repoFullSlug);

            // Update review to posted
            $review->update([
                'status' => ReviewStatus::Posted,
                'finished_at' => now(),
                'prompt_tokens' => $totalInputTokens,
                'completion_tokens' => $totalOutputTokens,
                'secrets_redacted' => $totalSecretsRedacted,
            ]);

            $costReservation->notifyIfThresholdExceeded($workspace, $costReservation->consumed($this->workspaceId));
            $this->emitReviewLog($review->fresh());

        } catch (LlmReviewException $e) {
            $this->handleLlmException($e, $review, $bitbucket, $repoFullSlug, $costReservation);
        } catch (Throwable $e) {
            $review->update([
                'status' => ReviewStatus::Failed,
                'error_class' => get_class($e),
                'error_message' => substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);

            Log::error('ReviewPullRequestJob: unexpected error', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);

            $this->emitReviewLog($review->fresh());

            throw $e;
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new BindWorkspaceMiddleware(app(WorkspaceContext::class))];
    }

    private function postSkippedSummary(
        BitbucketClient $bitbucket,
        string $repoFullSlug,
        Review $review,
        string $reason,
    ): void {
        $text = self::ERROR_COMMENT_MARKER.' '.$reason;

        $response = $bitbucket->postPullRequestComment(
            $repoFullSlug,
            $this->pullRequestNumber,
            $text,
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $this->workspaceId,
            'file_path' => null,
            'line' => null,
            'bitbucket_comment_id' => (string) ($response['id'] ?? null),
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Summary,
        ]);
    }

    private function handleLlmException(
        LlmReviewException $e,
        Review $review,
        BitbucketClient $bitbucket,
        string $repoFullSlug,
        CostReservationInterface $costReservation,
    ): void {
        $decision = $e->retryDecision;

        $review->update([
            'status' => ReviewStatus::Failed,
            'error_class' => get_class($e->getPrevious() ?? $e),
            'error_message' => substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);

        $costReservation->release($this->workspaceId, self::COST_RESERVATION_TOKENS);

        // Post an error summary comment so developers know the review failed
        try {
            $correlationId = $review->id;
            $text = self::ERROR_COMMENT_MARKER." Code review failed (correlation: {$correlationId}). The team has been notified.";

            $response = $bitbucket->postPullRequestComment($repoFullSlug, $this->pullRequestNumber, $text);

            ReviewComment::create([
                'review_id' => $review->id,
                'workspace_id' => $this->workspaceId,
                'file_path' => null,
                'line' => null,
                'bitbucket_comment_id' => (string) ($response['id'] ?? null),
                'posted_at' => Carbon::now(),
                'comment_type' => CommentType::Summary,
            ]);
        } catch (Throwable) {
            // Swallow — we're already in error handling, don't mask the original
        }

        Log::error('ReviewPullRequestJob: LLM error', [
            'review_id' => $review->id,
            'decision' => $decision->name,
            'error' => $e->getMessage(),
        ]);

        $this->emitReviewLog($review->fresh());

        if ($decision === RetryDecision::Terminal) {
            return; // Do not re-throw — mark failed and stop
        }

        throw $e; // RetryWithBackoff or PauseWorkspace → let Horizon retry/escalate
    }

    private function emitReviewLog(?Review $review): void
    {
        if ($review === null) {
            return;
        }

        $llmCallsCount = $review->llmCalls()->count();

        Log::channel('cdv-rabbit-reviews')->info('review.terminal', [
            'correlation_id' => $review->correlation_id,
            'workspace_id' => $review->workspace_id,
            'repository_id' => $review->repository_id,
            'pull_request_number' => $review->pull_request_number,
            'head_sha' => substr((string) $review->head_sha, 0, 8),
            'status' => $review->status?->value,
            'started_at' => $review->started_at?->toIso8601String(),
            'finished_at' => $review->finished_at?->toIso8601String(),
            'duration_ms' => $review->started_at && $review->finished_at
                ? (int) $review->started_at->diffInMilliseconds($review->finished_at)
                : null,
            'prompt_tokens' => $review->prompt_tokens,
            'completion_tokens' => $review->completion_tokens,
            'cost_usd_cents' => $review->cost_usd_cents,
            'secrets_redacted' => $review->secrets_redacted,
            'error_class' => $review->error_class,
            'error_message' => $review->error_message !== null
                ? substr($review->error_message, 0, 200)
                : null,
            'llm_calls_count' => $llmCallsCount,
        ]);
    }

    /**
     * Aggregate Bitbucket diffstat API response into the flat shape expected by SkipRules.
     *
     * @param  array<string, mixed>|null  $rawDiffStat
     * @return array{lines_added: int, lines_removed: int}
     */
    private function aggregateDiffStat(?array $rawDiffStat): array
    {
        if ($rawDiffStat === null) {
            return ['lines_added' => 0, 'lines_removed' => 0];
        }

        $linesAdded = 0;
        $linesRemoved = 0;

        foreach ($rawDiffStat['values'] ?? [] as $fileStat) {
            $linesAdded += (int) ($fileStat['lines_added'] ?? 0);
            $linesRemoved += (int) ($fileStat['lines_removed'] ?? 0);
        }

        return ['lines_added' => $linesAdded, 'lines_removed' => $linesRemoved];
    }

    /**
     * Aggregate per-file results into a single DTO for CommentPoster.
     *
     * @param  array<int, ReviewCommentDto>  $allComments
     */
    private function buildAggregatedResult(
        array $allComments,
        int $totalInputTokens,
        int $totalOutputTokens,
    ): ReviewResultDto {
        // Derive overall risk level from the highest-severity comment found
        $riskLevel = 'low';
        foreach ($allComments as $comment) {
            if ($comment->severity === 'high') {
                $riskLevel = 'high';
                break;
            }
            if ($comment->severity === 'medium') {
                $riskLevel = 'medium';
            }
        }

        $overview = match ($riskLevel) {
            'high' => 'Review complete. High-severity issues were found — please address before merging.',
            'medium' => 'Review complete. Some issues found that may need attention.',
            default => 'Review complete. No significant issues found.',
        };

        $summary = new ReviewSummaryDto(
            overview: $overview,
            riskLevel: $riskLevel,
        );

        return new ReviewResultDto(
            summary: $summary,
            comments: $allComments,
            inputTokens: $totalInputTokens,
            cacheCreationInputTokens: 0,
            cacheReadInputTokens: 0,
            outputTokens: $totalOutputTokens,
            requestId: null,
            rateLimitTokensRemaining: null,
            rateLimitTokensReset: null,
            durationMs: 0,
        );
    }
}
