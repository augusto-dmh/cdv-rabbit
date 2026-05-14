<?php

namespace App\Support;

/**
 * Request-scoped value object holding Anthropic response headers.
 * Populated by AnthropicTransportMiddleware after each HTTP response.
 */
final class AnthropicHeaderBag
{
    public function __construct(
        public readonly ?string $requestId = null,
        public readonly ?int $rateLimitTokensRemaining = null,
        public readonly ?string $rateLimitTokensReset = null,
    ) {}
}
