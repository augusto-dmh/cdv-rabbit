<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

final readonly class CritiqueResultDto
{
    /**
     * @param  list<array{finding_index: int, verdict: string, reason: string}>  $decisions
     */
    public function __construct(
        public array $decisions,
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
     * Return the list of finding indices the critic approved.
     *
     * @return list<int>
     */
    public function approvedFindingIndices(): array
    {
        $indices = [];
        foreach ($this->decisions as $decision) {
            if (($decision['verdict'] ?? '') === 'approve') {
                $indices[] = (int) $decision['finding_index'];
            }
        }

        sort($indices);

        return array_values(array_unique($indices));
    }

    /**
     * Return the list of finding indices the critic rejected.
     *
     * @return list<int>
     */
    public function rejectedFindingIndices(): array
    {
        $indices = [];
        foreach ($this->decisions as $decision) {
            if (($decision['verdict'] ?? '') === 'reject') {
                $indices[] = (int) $decision['finding_index'];
            }
        }

        sort($indices);

        return array_values(array_unique($indices));
    }

    /**
     * Pair each rejected finding from the supplied draft with the critic's reason.
     *
     * @return list<array{finding: ReviewFindingDto, reason: string}>
     */
    public function rejectedFindings(DraftReviewDto $draft): array
    {
        $out = [];
        foreach ($this->decisions as $decision) {
            if (($decision['verdict'] ?? '') !== 'reject') {
                continue;
            }
            $index = (int) ($decision['finding_index'] ?? -1);
            if (! isset($draft->findings[$index])) {
                continue;
            }
            $out[] = [
                'finding' => $draft->findings[$index],
                'reason' => (string) ($decision['reason'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Return a map of approved finding index → critic reason (may be empty string).
     *
     * @return array<int, string>
     */
    public function approvedReasonsByIndex(): array
    {
        $map = [];
        foreach ($this->decisions as $decision) {
            if (($decision['verdict'] ?? '') !== 'approve') {
                continue;
            }
            $map[(int) $decision['finding_index']] = (string) ($decision['reason'] ?? '');
        }

        return $map;
    }
}
