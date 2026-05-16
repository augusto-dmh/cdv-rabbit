<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

final readonly class DraftReviewDto
{
    /**
     * @param  list<ReviewFindingDto>  $findings
     * @param  list<ReviewNitpickDto>  $nitpicks
     */
    public function __construct(
        public ReviewSummaryV2Dto $summary,
        public array $findings,
        public array $nitpicks,
        public int $inputTokens,
        public int $cacheCreationInputTokens,
        public int $cacheReadInputTokens,
        public int $outputTokens,
        public ?string $requestId,
        public ?int $rateLimitTokensRemaining,
        public ?string $rateLimitTokensReset,
        public int $durationMs,
    ) {}

    /**
     * Return a new DraftReviewDto with findings restricted to the supplied indices.
     * Nitpicks, summary, and telemetry are preserved untouched.
     *
     * @param  list<int>  $indices
     */
    public function withFindingsAtIndices(array $indices): self
    {
        $kept = [];
        foreach ($indices as $index) {
            if (isset($this->findings[$index])) {
                $kept[] = $this->findings[$index];
            }
        }

        return new self(
            summary: $this->summary,
            findings: $kept,
            nitpicks: $this->nitpicks,
            inputTokens: $this->inputTokens,
            cacheCreationInputTokens: $this->cacheCreationInputTokens,
            cacheReadInputTokens: $this->cacheReadInputTokens,
            outputTokens: $this->outputTokens,
            requestId: $this->requestId,
            rateLimitTokensRemaining: $this->rateLimitTokensRemaining,
            rateLimitTokensReset: $this->rateLimitTokensReset,
            durationMs: $this->durationMs,
        );
    }

    /**
     * Render the draft as the JSON payload the critic call receives as user content.
     */
    public function toCriticInputArray(): array
    {
        $findings = [];
        foreach ($this->findings as $index => $finding) {
            $findings[] = [
                'finding_index' => $index,
                'path' => $finding->path,
                'line' => $finding->line,
                'severity' => $finding->severity,
                'category' => $finding->category,
                'message' => $finding->message,
                'suggestion' => $finding->suggestion,
            ];
        }

        return [
            'summary' => [
                'overview' => $this->summary->overview,
                'risk_level' => $this->summary->riskLevel,
                'walkthrough' => $this->summary->walkthrough,
            ],
            'findings' => $findings,
        ];
    }
}
