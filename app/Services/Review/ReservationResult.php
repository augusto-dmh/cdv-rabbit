<?php

declare(strict_types=1);

namespace App\Services\Review;

final class ReservationResult
{
    public function __construct(
        public readonly bool $granted,
        public readonly int $consumed,
        public readonly int $cap,
    ) {}

    public function denied(): bool
    {
        return ! $this->granted;
    }

    public function remaining(): int
    {
        return max(0, $this->cap - $this->consumed);
    }
}
