<?php

namespace App\Services\Review;

final class FileDiff
{
    public function __construct(
        public readonly string $path,
        public readonly string $hunks,
        public readonly bool $renamed,
        public readonly bool $binary,
        public readonly int $linesAdded,
        public readonly int $linesRemoved,
    ) {}
}
