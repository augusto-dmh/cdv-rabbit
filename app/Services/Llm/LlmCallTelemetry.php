<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\LlmCallRole;
use App\Models\Review;
use App\Models\ReviewsLlmCall;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewResultDto;
use Illuminate\Support\Carbon;

final class LlmCallTelemetry
{
    /**
     * Persist a reviews_llm_calls row for one LLM API call.
     * Zero prompt/response text is ever written — LGPD compliance by design.
     */
    public function record(
        Review $review,
        string $provider,
        string $modelId,
        LlmCallRole $role,
        ReviewResultDto $result,
        int $httpStatus = 200,
        ?string $errorType = null,
    ): ReviewsLlmCall {
        return $this->persist(
            review: $review,
            provider: $provider,
            modelId: $modelId,
            role: $role,
            inputTokens: $result->inputTokens,
            cacheCreationInputTokens: $result->cacheCreationInputTokens,
            cacheReadInputTokens: $result->cacheReadInputTokens,
            outputTokens: $result->outputTokens,
            requestId: $result->requestId,
            rateLimitTokensRemaining: $result->rateLimitTokensRemaining,
            rateLimitTokensReset: $result->rateLimitTokensReset,
            durationMs: $result->durationMs,
            httpStatus: $httpStatus,
            errorType: $errorType,
        );
    }

    /**
     * Persist telemetry for a v2 draft call.
     */
    public function recordDraft(
        Review $review,
        string $provider,
        string $modelId,
        DraftReviewDto $draft,
        int $httpStatus = 200,
        ?string $errorType = null,
    ): ReviewsLlmCall {
        return $this->persist(
            review: $review,
            provider: $provider,
            modelId: $modelId,
            role: LlmCallRole::Draft,
            inputTokens: $draft->inputTokens,
            cacheCreationInputTokens: $draft->cacheCreationInputTokens,
            cacheReadInputTokens: $draft->cacheReadInputTokens,
            outputTokens: $draft->outputTokens,
            requestId: $draft->requestId,
            rateLimitTokensRemaining: $draft->rateLimitTokensRemaining,
            rateLimitTokensReset: $draft->rateLimitTokensReset,
            durationMs: $draft->durationMs,
            httpStatus: $httpStatus,
            errorType: $errorType,
        );
    }

    /**
     * Persist telemetry for a v2 critique call.
     */
    public function recordCritique(
        Review $review,
        string $provider,
        string $modelId,
        CritiqueResultDto $critique,
        int $httpStatus = 200,
        ?string $errorType = null,
    ): ReviewsLlmCall {
        return $this->persist(
            review: $review,
            provider: $provider,
            modelId: $modelId,
            role: LlmCallRole::Critique,
            inputTokens: $critique->inputTokens,
            cacheCreationInputTokens: $critique->cacheCreationInputTokens,
            cacheReadInputTokens: $critique->cacheReadInputTokens,
            outputTokens: $critique->outputTokens,
            requestId: $critique->requestId,
            rateLimitTokensRemaining: $critique->rateLimitTokensRemaining,
            rateLimitTokensReset: $critique->rateLimitTokensReset,
            durationMs: $critique->durationMs,
            httpStatus: $httpStatus,
            errorType: $errorType,
        );
    }

    private function persist(
        Review $review,
        string $provider,
        string $modelId,
        LlmCallRole $role,
        int $inputTokens,
        int $cacheCreationInputTokens,
        int $cacheReadInputTokens,
        int $outputTokens,
        ?string $requestId,
        ?int $rateLimitTokensRemaining,
        ?string $rateLimitTokensReset,
        int $durationMs,
        int $httpStatus,
        ?string $errorType,
    ): ReviewsLlmCall {
        return ReviewsLlmCall::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'provider' => $provider,
            'model_id' => $modelId,
            'model' => $modelId,
            'role' => $role,
            'input_tokens' => $inputTokens,
            'cache_creation_input_tokens' => $cacheCreationInputTokens,
            'cache_read_input_tokens' => $cacheReadInputTokens,
            'output_tokens' => $outputTokens,
            'request_id' => $requestId,
            'ratelimit_tokens_remaining' => $rateLimitTokensRemaining,
            'ratelimit_tokens_reset' => $rateLimitTokensReset !== null
                ? Carbon::parse($rateLimitTokensReset)
                : null,
            'duration_ms' => $durationMs,
            'http_status' => $httpStatus,
            'error_type' => $errorType,
            'created_at' => now(),
        ]);
    }
}
