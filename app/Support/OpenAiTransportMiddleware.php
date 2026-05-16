<?php

namespace App\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
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
                            rateLimitTokensReset: self::normalizeResetHeader($reset),
                        ),
                    );

                    return $response;
                }
            );
        };
    }

    /**
     * Normalize OpenAI's duration-format reset header ("5.123s", "1m30s",
     * "200ms") into an absolute ISO-8601 timestamp string. Returns null when
     * the header is missing or unparseable so downstream Carbon::parse stays
     * provider-agnostic (Anthropic already returns an ISO-8601 string).
     */
    private static function normalizeResetHeader(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        if (! preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h)/', $raw, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $seconds = 0.0;

        foreach ($matches as [, $value, $unit]) {
            $seconds += match ($unit) {
                'ms' => (float) $value / 1000,
                's' => (float) $value,
                'm' => (float) $value * 60,
                'h' => (float) $value * 3600,
            };
        }

        if ($seconds <= 0) {
            return null;
        }

        return Carbon::now()->addMilliseconds((int) round($seconds * 1000))->toIso8601String();
    }
}
