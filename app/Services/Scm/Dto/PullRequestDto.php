<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class PullRequestDto
{
    public function __construct(
        public int $number,
        public string $title,
        public string $state,
        public string $sourceBranch,
        public string $targetBranch,
        public string $headSha,
        public string $baseSha,
        public string $authorLogin,
    ) {}
}
