<?php

namespace App\Support;

use Closure;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that captures OpenAI response headers into a
 * request-scoped OpenAiHeaderBag singleton so OpenAiReviewer can
 * read them after each call to persist in reviews_llm_calls.
 */
final class OpenAiTransportMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($request, $options)->then(
                function (ResponseInterface $response): ResponseInterface {
                    $remaining = $response->getHeaderLine('x-ratelimit-remaining-tokens');
                    $reset = $response->getHeaderLine('x-ratelimit-reset-tokens');

                    $this->container->instance(
                        OpenAiHeaderBag::class,
                        new OpenAiHeaderBag(
                            requestId: $response->getHeaderLine('x-request-id') ?: null,
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
