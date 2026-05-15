<?php

namespace App\Support;

/**
 * Request-scoped value object holding OpenAI response headers.
 * Populated after each HTTP response to mirror AnthropicHeaderBag.
 */
final class OpenAiHeaderBag
{
    public function __construct(
        public readonly ?string $requestId = null,
        public readonly ?int $rateLimitTokensRemaining = null,
        public readonly ?string $rateLimitTokensReset = null,
    ) {}
}
