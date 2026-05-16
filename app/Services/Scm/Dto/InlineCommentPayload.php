<?php

declare(strict_types=1);

namespace App\Services\Scm\Dto;

final readonly class InlineCommentPayload
{
    public function __construct(
        public string $body,
        public string $path,
        public int $line,
        public string $headSha,
    ) {}
}
