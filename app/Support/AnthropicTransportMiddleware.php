<?php

namespace App\Support;

use Closure;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that captures Anthropic response headers into a
 * request-scoped AnthropicHeaderBag singleton so ClaudeReviewer can
 * read them after each call to persist in reviews_llm_calls.
 */
final class AnthropicTransportMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($request, $options)->then(
                function (ResponseInterface $response): ResponseInterface {
                    $remaining = $response->getHeaderLine('anthropic-ratelimit-tokens-remaining');
                    $reset = $response->getHeaderLine('anthropic-ratelimit-tokens-reset');

                    $this->container->instance(
                        AnthropicHeaderBag::class,
                        new AnthropicHeaderBag(
                            requestId: $response->getHeaderLine('request-id') ?: null,
                            rateLimitTokensRemaining: $remaining !== '' ? (int) $remaining : null,
                            rateLimitTokensReset: $reset ?: null,
                        ),
                    );

                    return $response;
                }
            );
        };
    }
}
