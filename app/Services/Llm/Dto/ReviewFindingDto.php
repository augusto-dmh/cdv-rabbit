<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

final readonly class ReviewFindingDto
{
    public function __construct(
        public string $path,
        public int $line,
        public string $severity,
        public string $category,
        public string $message,
        public ?string $suggestion,
    ) {}

    /**
     * @param  array{path: string, line: int, severity: string, category: string, message: string, suggestion?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            line: $data['line'],
            severity: $data['severity'],
            category: $data['category'],
            message: $data['message'],
            suggestion: $data['suggestion'] ?? null,
        );
    }
}
