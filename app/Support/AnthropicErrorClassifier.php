<?php

namespace App\Support;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Maps Anthropic API errors to a retry policy per plan §3.0.7.
 *
 * | HTTP | Anthropic type              | RetryDecision        |
 * |------|-----------------------------|----------------------|
 * | 400  | invalid_request_error       | Terminal             |
 * | 401  | authentication_error        | PauseWorkspace       |
 * | 402  | billing_error               | PauseWorkspace       |
 * | 403  | permission_error            | Terminal             |
 * | 404  | not_found_error             | Terminal             |
 * | 413  | request_too_large           | Terminal             |
 * | 429  | rate_limit_error            | RetryWithBackoff     |
 * | 5xx  | api_error / timeout_error   | RetryWithBackoff     |
 * | 529  | overloaded_error            | RetryWithBackoff     |
 */
final class AnthropicErrorClassifier
{
    public function classify(Throwable $e): RetryDecision
    {
        if ($e instanceof RateLimitedException) {
            return RetryDecision::RetryWithBackoff;
        }

        if ($e instanceof ProviderOverloadedException) {
            return RetryDecision::RetryWithBackoff;
        }

        if ($e instanceof InsufficientCreditsException) {
            return RetryDecision::PauseWorkspace;
        }

        $status = $this->resolveHttpStatus($e);

        if ($status !== null) {
            return $this->classifyByStatus($status, $e);
        }

        return RetryDecision::Terminal;
    }

    private function classifyByStatus(int $status, Throwable $e): RetryDecision
    {
        return match (true) {
            $status === 401 => RetryDecision::PauseWorkspace,
            $status === 402 => RetryDecision::PauseWorkspace,
            $status === 400 => RetryDecision::Terminal,
            $status === 403 => RetryDecision::Terminal,
            $status === 404 => RetryDecision::Terminal,
            $status === 413 => RetryDecision::Terminal,
            $status === 429 => RetryDecision::RetryWithBackoff,
            $status === 529 => RetryDecision::RetryWithBackoff,
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
            if ($current instanceof GuzzleRequestException && $current->getResponse() !== null) {
                return $current->getResponse()->getStatusCode();
            }
            $current = $current->getPrevious();
        }

        return null;
    }
}
