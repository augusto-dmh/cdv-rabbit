<?php

namespace App\Support;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Maps OpenAI API errors to a retry policy.
 *
 * | HTTP | OpenAI error.code / error.type | RetryDecision    |
 * |------|-------------------------------|------------------|
 * | 429  | rate_limit_exceeded           | RetryWithBackoff |
 * | 429  | insufficient_quota            | Terminal         |
 * | 400  | context_length_exceeded       | Terminal         |
 * | 400  | invalid_request_error         | Terminal         |
 * | 5xx  | server_error                  | RetryWithBackoff |
 * | *    | unknown                       | Terminal (closed)|
 *
 * SDK-wrapped exceptions: laravel/ai converts 429 responses into
 * RateLimitedException before our classifier sees them, passing the original
 * Illuminate\Http\Client\RequestException as $previous. We walk the chain to
 * read error.code from the body. If the code is unreadable but the chain
 * confirms HTTP 429, we default to RetryWithBackoff (conservative). Only when
 * nothing in the chain signals 429 do we fall back to Terminal.
 */
final class OpenAiErrorClassifier
{
    public function classify(Throwable $e): RetryDecision
    {
        $status = $this->resolveHttpStatus($e);
        $errorCode = $this->resolveErrorCode($e);

        if ($errorCode !== null) {
            return $this->classifyByCode($errorCode, $status);
        }

        if ($status !== null) {
            return $this->classifyByStatus($status);
        }

        // laravel/ai wraps 429 responses in RateLimitedException before our classifier
        // sees them. If the previous-chain traversal above could not extract a body or
        // status (e.g. the SDK threw without a response), we still know the SDK only
        // raises RateLimitedException for rate-limit conditions — treat it as recoverable.
        if ($e instanceof RateLimitedException) {
            return RetryDecision::RetryWithBackoff;
        }

        return RetryDecision::Terminal;
    }

    private function classifyByCode(string $code, ?int $status): RetryDecision
    {
        return match ($code) {
            'rate_limit_exceeded' => RetryDecision::RetryWithBackoff,
            'insufficient_quota' => RetryDecision::Terminal,
            'context_length_exceeded' => RetryDecision::Terminal,
            'invalid_request_error' => RetryDecision::Terminal,
            'server_error' => RetryDecision::RetryWithBackoff,
            default => $status !== null ? $this->classifyByStatus($status) : RetryDecision::Terminal,
        };
    }

    private function classifyByStatus(int $status): RetryDecision
    {
        return match (true) {
            $status === 429 => RetryDecision::RetryWithBackoff,
            $status >= 500 => RetryDecision::RetryWithBackoff,
            default => RetryDecision::Terminal,
        };
    }

    private function resolveHttpStatus(Throwable $e): ?int
    {
        if ($e instanceof LaravelRequestException) {
            return $e->response->status();
        }

        $current = $e;
        while ($current !== null) {
            if ($current instanceof LaravelRequestException) {
                return $current->response->status();
            }

            if ($current instanceof GuzzleRequestException && $current->getResponse() !== null) {
                return $current->getResponse()->getStatusCode();
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    private function resolveErrorCode(Throwable $e): ?string
    {
        if ($e instanceof LaravelRequestException) {
            $body = json_decode((string) $e->response->getBody(), true);

            return $body['error']['code'] ?? $body['error']['type'] ?? null;
        }

        $current = $e;
        while ($current !== null) {
            if ($current instanceof LaravelRequestException) {
                $body = json_decode((string) $current->response->getBody(), true);
                $code = $body['error']['code'] ?? $body['error']['type'] ?? null;
                if ($code !== null) {
                    return $code;
                }
            }

            if ($current instanceof GuzzleRequestException && $current->getResponse() !== null) {
                $body = json_decode((string) $current->getResponse()->getBody(), true);
                $code = $body['error']['code'] ?? $body['error']['type'] ?? null;
                if ($code !== null) {
                    return $code;
                }
            }

            $current = $current->getPrevious();
        }

        return null;
    }
}
