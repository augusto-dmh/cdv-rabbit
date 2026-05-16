<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class CommentHandle
{
    /**
     * @param  array<string, mixed>|null  $providerSpecific
     */
    public function __construct(
        public string $scmCommentId,
        public ?array $providerSpecific = null,
    ) {}
}
