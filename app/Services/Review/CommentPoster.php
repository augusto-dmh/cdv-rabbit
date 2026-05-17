<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\CommentType;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\InlineCommentPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class CommentPoster
{
    private const MAX_INLINE_COMMENTS = 25;

    private const AI_MARKER = '🤖 cdv-rabbit (AI generated):';

    public function __construct(
        private readonly CommentSanitizer $sanitizer,
    ) {}

    /**
     * Post review results to the SCM via the active driver and persist review_comments rows.
     * AC5: inline comments capped at 25.
     * AC6: every comment prefixed with AI_MARKER.
     * AC7: existing (path, line) comment rows trigger update-in-place.
     * AC26: empty comments + risk_level=low → summary only.
     */
    public function post(Review $review, ReviewResultDto $dto, string $scmRepoId, ScmDriverInterface $driver): void
    {
        $comments = $dto->comments;
        $isNoIssuesPath = count($comments) === 0 && $dto->summary->riskLevel === 'low';

        if ($isNoIssuesPath) {
            $this->postSummary($review, $dto, $scmRepoId, $driver, overflowCount: 0);

            return;
        }

        $overflow = 0;
        if (count($comments) > self::MAX_INLINE_COMMENTS) {
            $overflow = count($comments) - self::MAX_INLINE_COMMENTS;
            $comments = array_slice($comments, 0, self::MAX_INLINE_COMMENTS);
        }

        foreach ($comments as $commentDto) {
            $this->postInlineComment($review, $commentDto, $scmRepoId, $driver);
        }

        $this->postSummary($review, $dto, $scmRepoId, $driver, overflowCount: $overflow);
    }

    /**
     * v2 path — Walkthrough + tiered Findings inline + Nitpicks collapsed in <details>.
     *
     * AC46: only the supplied Findings reach inline comments (caller already filtered).
     * AC48: Nitpicks are NEVER posted inline; they live in the <details> block of the summary.
     * AC5  cap still applies to inline Findings.
     */
    public function postV2(
        Review $review,
        DraftReviewDto $draft,
        string $scmRepoId,
        ScmDriverInterface $driver,
        ?DiffPositionResolver $resolver = null,
    ): void {
        $findings = $draft->findings;

        $overflow = 0;
        if (count($findings) > self::MAX_INLINE_COMMENTS) {
            $overflow = count($findings) - self::MAX_INLINE_COMMENTS;
            $findings = array_slice($findings, 0, self::MAX_INLINE_COMMENTS);
        }

        $unresolved = [];
        foreach ($findings as $finding) {
            $resolvedFinding = $this->resolveFinding($finding, $resolver);

            if (! $this->tryPostInlineFinding($review, $resolvedFinding, $scmRepoId, $driver)) {
                $unresolved[] = $finding;
            }
        }

        $this->postV2Summary($review, $draft, $scmRepoId, $driver, overflowCount: $overflow, unresolved: $unresolved);
    }

    /**
     * Opportunistic pre-flight resolve. When the resolver can correct the
     * (path, line) — e.g. namespace-cased path "App/..." → filesystem
     * "app/...", or off-by-a-few line → nearest `+` line within ±5 — apply
     * the correction. When it can't, pass the Finding through unchanged
     * and let the SCM-422 fallback in tryPostInlineFinding catch real
     * unresolvable positions. This keeps the resolver strictly additive:
     * Findings only get better, never blocked.
     */
    private function resolveFinding(ReviewFindingDto $finding, ?DiffPositionResolver $resolver): ReviewFindingDto
    {
        if ($resolver === null) {
            return $finding;
        }

        $resolved = $resolver->resolve($finding->path, $finding->line);

        if ($resolved === null) {
            return $finding;
        }

        if ($resolved['path'] === $finding->path && $resolved['line'] === $finding->line) {
            return $finding;
        }

        return new ReviewFindingDto(
            path: $resolved['path'],
            line: $resolved['line'],
            severity: $finding->severity,
            category: $finding->category,
            message: $finding->message,
            suggestion: $finding->suggestion,
            agentPrompt: $finding->agentPrompt,
        );
    }

    /**
     * Try to post an inline Finding. Returns true on success, false when the SCM
     * rejects the (path, line) — typical when the LLM emits an off-by-a-few line
     * that doesn't anchor to a real `+` line on the head commit (e.g. blank
     * lines, lines outside the diff hunk). Caller folds rejected Findings into
     * the summary body via postV2Summary so they still reach the PR.
     */
    private function tryPostInlineFinding(
        Review $review,
        ReviewFindingDto $finding,
        string $scmRepoId,
        ScmDriverInterface $driver,
    ): bool {
        try {
            $this->postInlineFinding($review, $finding, $scmRepoId, $driver);

            return true;
        } catch (\RuntimeException $e) {
            // SCM driver throws RuntimeException with the upstream payload on 422
            // ("path could not be resolved"). Surface to the structured log and
            // fall back to the summary body — never silence the Finding.
            Log::warning('CommentPoster: inline post fell back to summary', [
                'review_id' => $review->id,
                'path' => $finding->path,
                'line' => $finding->line,
                'severity' => $finding->severity,
                'scm_error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function postInlineComment(
        Review $review,
        ReviewCommentDto $commentDto,
        string $scmRepoId,
        ScmDriverInterface $driver,
    ): void {
        $rawMessage = self::AI_MARKER.' '.$this->sanitizer->sanitize($commentDto->message);

        // AC7: look for an existing comment row for this (review's PR, path, line).
        $existing = ReviewComment::where('review_id', $review->id)
            ->where('file_path', $commentDto->path)
            ->where('line', $commentDto->line)
            ->where('comment_type', CommentType::Inline)
            ->whereNotNull('bitbucket_comment_id')
            ->first();

        if ($existing !== null) {
            $driver->updateComment(
                $scmRepoId,
                $review->pull_request_number,
                new CommentHandle(scmCommentId: (string) $existing->bitbucket_comment_id),
                $rawMessage,
            );

            $existing->update(['posted_at' => now()]);

            return;
        }

        $handle = $driver->postInlineComment(
            $scmRepoId,
            $review->pull_request_number,
            new InlineCommentPayload(
                body: $rawMessage,
                path: $commentDto->path,
                line: $commentDto->line,
                headSha: (string) $review->head_sha,
            ),
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => $commentDto->path,
            'line' => $commentDto->line,
            'bitbucket_comment_id' => $handle->scmCommentId,
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Inline,
        ]);
    }

    private function postInlineFinding(
        Review $review,
        ReviewFindingDto $finding,
        string $scmRepoId,
        ScmDriverInterface $driver,
    ): void {
        $body = sprintf(
            '[%s] [%s] %s',
            strtoupper($finding->severity),
            strtoupper($finding->category),
            $finding->message,
        );

        if ($finding->suggestion !== null && trim($finding->suggestion) !== '') {
            $body .= "\n\n**Suggested fix:**\n".$finding->suggestion;
        }

        $rawMessage = self::AI_MARKER.' '.$this->sanitizer->sanitize($body);

        // Agent Prompt footer (ADR 0006): rendered outside sanitize() so the
        // `<details>`/`<summary>` structural tags survive strip_tags(); the
        // prompt body itself is sanitized inside renderAgentPromptBlock().
        $agentPromptBlock = $this->renderAgentPromptBlock($finding->agentPrompt);
        if ($agentPromptBlock !== '') {
            $rawMessage .= "\n\n".$agentPromptBlock;
        }

        $existing = ReviewComment::where('review_id', $review->id)
            ->where('file_path', $finding->path)
            ->where('line', $finding->line)
            ->where('comment_type', CommentType::Inline)
            ->whereNotNull('bitbucket_comment_id')
            ->first();

        if ($existing !== null) {
            $driver->updateComment(
                $scmRepoId,
                $review->pull_request_number,
                new CommentHandle(scmCommentId: (string) $existing->bitbucket_comment_id),
                $rawMessage,
            );

            $existing->update(['posted_at' => now()]);

            return;
        }

        $handle = $driver->postInlineComment(
            $scmRepoId,
            $review->pull_request_number,
            new InlineCommentPayload(
                body: $rawMessage,
                path: $finding->path,
                line: $finding->line,
                headSha: (string) $review->head_sha,
            ),
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => $finding->path,
            'line' => $finding->line,
            'bitbucket_comment_id' => $handle->scmCommentId,
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Inline,
        ]);
    }

    private function postSummary(
        Review $review,
        ReviewResultDto $dto,
        string $scmRepoId,
        ScmDriverInterface $driver,
        int $overflowCount,
    ): void {
        $overview = $this->sanitizer->sanitize($dto->summary->overview);
        $riskLabel = strtoupper($dto->summary->riskLevel);

        $text = self::AI_MARKER." **Code Review Summary** (Risk: {$riskLabel})\n\n{$overview}";

        if ($overflowCount > 0) {
            $text .= "\n\n_+{$overflowCount} more findings were omitted due to the 25-comment cap. See individual file diffs for full details._";
        }

        $handle = $driver->postPullRequestComment(
            $scmRepoId,
            $review->pull_request_number,
            $text,
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => null,
            'line' => null,
            'bitbucket_comment_id' => $handle->scmCommentId,
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Summary,
        ]);
    }

    /**
     * @param  list<ReviewFindingDto>  $unresolved
     */
    private function postV2Summary(
        Review $review,
        DraftReviewDto $draft,
        string $scmRepoId,
        ScmDriverInterface $driver,
        int $overflowCount,
        array $unresolved = [],
    ): void {
        $walkthrough = $this->sanitizer->sanitize($draft->summary->walkthrough);
        $overview = $this->sanitizer->sanitize($draft->summary->overview);
        $riskLabel = strtoupper($draft->summary->riskLevel);

        $high = $this->countSeverity($draft->findings, 'high');
        $medium = $this->countSeverity($draft->findings, 'medium');
        $low = $this->countSeverity($draft->findings, 'low');

        $text = self::AI_MARKER." **Code Review Summary** (Risk: {$riskLabel})\n\n";
        $text .= "## Walkthrough\n{$walkthrough}\n\n";
        $text .= "{$overview}\n\n";
        $text .= "## Findings (high: {$high}, medium: {$medium}, low: {$low})\n\n";

        if ($overflowCount > 0) {
            $text .= "_+{$overflowCount} more Findings were omitted due to the 25-comment cap. See individual file diffs for full details._\n\n";
        }

        if (count($unresolved) > 0) {
            $text .= "<details>\n<summary>Unresolved Findings (".count($unresolved).") — couldn't anchor inline; reported here</summary>\n\n";
            foreach ($unresolved as $finding) {
                $sev = strtoupper($finding->severity);
                $cat = strtoupper($finding->category);
                $msg = $this->sanitizer->sanitize($finding->message);
                $text .= "- `{$finding->path}:{$finding->line}` — [{$sev}] [{$cat}] {$msg}\n";

                // AC55: nest the Agent Prompt block inside the unresolved bullet
                // when present — exactly where the developer most needs it,
                // since the issue cannot be seen inline.
                $agentPromptBlock = $this->renderAgentPromptBlock($finding->agentPrompt);
                if ($agentPromptBlock !== '') {
                    $text .= "\n".$agentPromptBlock."\n";
                }
            }
            $text .= "\n</details>\n\n";
        }

        $nitpickCount = count($draft->nitpicks);
        if ($nitpickCount > 0) {
            $text .= "<details>\n<summary>Nitpicks ({$nitpickCount})</summary>\n\n";
            foreach ($draft->nitpicks as $nitpick) {
                $line = $this->renderNitpickLine($nitpick);
                $text .= "- {$line}\n";
            }
            $text .= "\n</details>\n";
        }

        $handle = $driver->postPullRequestComment(
            $scmRepoId,
            $review->pull_request_number,
            $text,
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => null,
            'line' => null,
            'bitbucket_comment_id' => $handle->scmCommentId,
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Summary,
        ]);
    }

    /**
     * @param  list<ReviewFindingDto>  $findings
     */
    private function countSeverity(array $findings, string $severity): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if ($finding->severity === $severity) {
                $count++;
            }
        }

        return $count;
    }

    private function renderNitpickLine(ReviewNitpickDto $nitpick): string
    {
        $message = $this->sanitizer->sanitize($nitpick->message);

        return "`{$nitpick->path}:{$nitpick->line}` — {$message}";
    }

    /**
     * Render the CodeRabbit-parity `🤖 Prompt for AI Agents` footer block.
     *
     * Returns an empty string when the prompt body is null or whitespace —
     * caller skips appending so the comment renders clean (AC56).
     * The fixed preamble is concatenated here (never LLM-emitted, ADR 0006);
     * the prompt body is sanitized via the path-aware CommentSanitizer (AC53)
     * so `@<path>` references survive while `@<username>` mentions die.
     */
    private function renderAgentPromptBlock(?string $promptBody): string
    {
        if ($promptBody === null) {
            return '';
        }

        $sanitized = $this->sanitizer->sanitize($promptBody);
        if ($sanitized === '') {
            return '';
        }

        $preamble = 'Verify each finding against the current code and only fix it if needed.';

        return "<details>\n<summary>🤖 Prompt for AI Agents</summary>\n\n```\n{$preamble}\n\n{$sanitized}\n```\n\n</details>";
    }
}
