<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class FileChangeDto
{
    public function __construct(
        public string $path,
        public string $status,
        public int $linesAdded,
        public int $linesRemoved,
    ) {}
}
