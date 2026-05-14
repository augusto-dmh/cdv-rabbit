<?php

namespace App\Services\Llm\Dto;

final class ReviewResultDto
{
    /**
     * @param  list<ReviewCommentDto>  $comments
     */
    public function __construct(
        public readonly ReviewSummaryDto $summary,
        public readonly array $comments,
        public readonly int $inputTokens,
        public readonly int $cacheCreationInputTokens,
        public readonly int $cacheReadInputTokens,
        public readonly int $outputTokens,
        public readonly ?string $requestId,
        public readonly ?int $rateLimitTokensRemaining,
        public readonly ?string $rateLimitTokensReset,
        public readonly int $durationMs,
    ) {}
}
