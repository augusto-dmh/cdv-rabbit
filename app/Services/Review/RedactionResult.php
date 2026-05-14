<?php

namespace App\Services\Review;

final class RedactionResult
{
    /**
     * @param  list<string>  $matchedPatterns
     */
    public function __construct(
        public readonly string $sanitized,
        public readonly int $count,
        public readonly array $matchedPatterns,
    ) {}
}
