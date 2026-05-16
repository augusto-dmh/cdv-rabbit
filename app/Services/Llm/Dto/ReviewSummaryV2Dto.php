<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

final readonly class ReviewSummaryV2Dto
{
    /**
     * @param  list<array{path: string, classification: string}>  $filesAnalyzed
     */
    public function __construct(
        public string $overview,
        public string $riskLevel,
        public string $walkthrough,
        public array $filesAnalyzed,
    ) {}

    /**
     * @param  array{overview: string, risk_level: string, walkthrough: string, files_analyzed?: list<array{path: string, classification: string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            overview: $data['overview'],
            riskLevel: $data['risk_level'],
            walkthrough: $data['walkthrough'],
            filesAnalyzed: $data['files_analyzed'] ?? [],
        );
    }
}
