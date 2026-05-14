<?php

namespace App\Services\Llm\Dto;

final class ReviewCommentDto
{
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly string $severity,
        public readonly string $message,
    ) {}

    /**
     * @param  array{path: string, line: int, severity: string, message: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            line: $data['line'],
            severity: $data['severity'],
            message: $data['message'],
        );
    }
}
