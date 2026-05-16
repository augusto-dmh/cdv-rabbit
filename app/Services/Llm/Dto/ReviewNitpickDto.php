<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

final readonly class ReviewNitpickDto
{
    public function __construct(
        public string $path,
        public int $line,
        public string $message,
    ) {}

    /**
     * @param  array{path: string, line: int, message: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            line: $data['line'],
            message: $data['message'],
        );
    }
}
