<?php

namespace App\Services\Llm\Dto;

final class ReviewSummaryDto
{
    public function __construct(
        public readonly string $overview,
        public readonly string $riskLevel,
    ) {}

    /**
     * @param  array{overview: string, risk_level: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            overview: $data['overview'],
            riskLevel: $data['risk_level'],
        );
    }
}
