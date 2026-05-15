<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\LlmCallRole;
use App\Models\Review;
use App\Models\ReviewsLlmCall;
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
        return ReviewsLlmCall::create([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
            'provider' => $provider,
            'model_id' => $modelId,
            'model' => $modelId,
            'role' => $role,
            'input_tokens' => $result->inputTokens,
            'cache_creation_input_tokens' => $result->cacheCreationInputTokens,
            'cache_read_input_tokens' => $result->cacheReadInputTokens,
            'output_tokens' => $result->outputTokens,
            'request_id' => $result->requestId,
            'ratelimit_tokens_remaining' => $result->rateLimitTokensRemaining,
            'ratelimit_tokens_reset' => $result->rateLimitTokensReset !== null
                ? Carbon::parse($result->rateLimitTokensReset)
                : null,
            'duration_ms' => $result->durationMs,
            'http_status' => $httpStatus,
            'error_type' => $errorType,
            'created_at' => now(),
        ]);
    }
}
