<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class CredentialCheck
{
    public function __construct(
        public bool $valid,
        public string $identity,
        public ?string $reason = null,
    ) {}
}
