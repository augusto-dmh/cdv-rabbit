<?php

namespace App\Services\Llm;

use App\Support\RetryDecision;
use RuntimeException;
use Throwable;

final class LlmReviewException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly RetryDecision $retryDecision,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
