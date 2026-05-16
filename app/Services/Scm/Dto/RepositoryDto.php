<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class RepositoryDto
{
    public function __construct(
        public string $scmRepoId,
        public string $ownerSlug,
        public string $name,
        public string $fullName,
        public string $defaultBranch,
        public bool $isPrivate = true,
    ) {}
}
