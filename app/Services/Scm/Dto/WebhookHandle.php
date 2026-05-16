<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class WebhookHandle
{
    public function __construct(
        public ?string $scmWebhookUuid,
    ) {}
}
