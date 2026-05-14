<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\CommentType;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use Illuminate\Support\Carbon;

final class CommentPoster
{
    private const MAX_INLINE_COMMENTS = 25;

    private const AI_MARKER = '🤖 cdv-rabbit (AI generated):';

    public function __construct(
        private readonly BitbucketClient $bitbucket,
        private readonly CommentSanitizer $sanitizer,
    ) {}

    /**
     * Post review results to Bitbucket and persist review_comments rows.
     * AC5: inline comments capped at 25.
     * AC6: every comment prefixed with AI_MARKER.
     * AC7: existing (path, line) comment rows trigger update-in-place.
     * AC26: empty comments + risk_level=low → summary only.
     */
    public function post(Review $review, ReviewResultDto $dto, string $repoFullSlug): void
    {
        $comments = $dto->comments;
        $isNoIssuesPath = count($comments) === 0 && $dto->summary->riskLevel === 'low';

        if ($isNoIssuesPath) {
            $this->postSummary($review, $dto, $repoFullSlug, overflowCount: 0);

            return;
        }

        $overflow = 0;
        if (count($comments) > self::MAX_INLINE_COMMENTS) {
            $overflow = count($comments) - self::MAX_INLINE_COMMENTS;
            $comments = array_slice($comments, 0, self::MAX_INLINE_COMMENTS);
        }

        foreach ($comments as $commentDto) {
            $this->postInlineComment($review, $commentDto, $repoFullSlug);
        }

        $this->postSummary($review, $dto, $repoFullSlug, overflowCount: $overflow);
    }

    private function postInlineComment(
        Review $review,
        ReviewCommentDto $commentDto,
        string $repoFullSlug,
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
            $this->bitbucket->updateComment(
                $repoFullSlug,
                $review->pull_request_number,
                (int) $existing->bitbucket_comment_id,
                $rawMessage,
            );

            $existing->update(['posted_at' => now()]);

            return;
        }

        $response = $this->bitbucket->postInlineComment(
            $repoFullSlug,
            $review->pull_request_number,
            $rawMessage,
            $commentDto->path,
            $commentDto->line,
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => $commentDto->path,
            'line' => $commentDto->line,
            'bitbucket_comment_id' => (string) ($response['id'] ?? null),
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Inline,
        ]);
    }

    private function postSummary(
        Review $review,
        ReviewResultDto $dto,
        string $repoFullSlug,
        int $overflowCount,
    ): void {
        $overview = $this->sanitizer->sanitize($dto->summary->overview);
        $riskLabel = strtoupper($dto->summary->riskLevel);

        $text = self::AI_MARKER." **Code Review Summary** (Risk: {$riskLabel})\n\n{$overview}";

        if ($overflowCount > 0) {
            $text .= "\n\n_+{$overflowCount} more findings were omitted due to the 25-comment cap. See individual file diffs for full details._";
        }

        $response = $this->bitbucket->postPullRequestComment(
            $repoFullSlug,
            $review->pull_request_number,
            $text,
        );

        ReviewComment::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'file_path' => null,
            'line' => null,
            'bitbucket_comment_id' => (string) ($response['id'] ?? null),
            'posted_at' => Carbon::now(),
            'comment_type' => CommentType::Summary,
        ]);
    }
}
