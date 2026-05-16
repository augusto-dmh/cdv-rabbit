<?php

declare(strict_types=1);

namespace App\Services\Eval;

final class EvalResult
{
    /**
     * @param  list<array<string, mixed>>  $actualFindings
     * @param  list<array{actual_idx: int, expected_idx: int}>  $matches
     * @param  list<int>  $missedExpected
     * @param  list<int>  $unexpectedActual
     */
    public function __construct(
        public readonly string $fixtureId,
        public readonly string $provider,
        public readonly string $schemaVersion,
        public readonly array $actualFindings,
        public readonly array $matches,
        public readonly array $missedExpected,
        public readonly array $unexpectedActual,
        public readonly float $recall,
        public readonly float $precision,
        public readonly ?string $error = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fixture_id' => $this->fixtureId,
            'provider' => $this->provider,
            'schema_version' => $this->schemaVersion,
            'recall' => $this->recall,
            'precision' => $this->precision,
            'actual_count' => count($this->actualFindings),
            'matches' => $this->matches,
            'missed_expected' => $this->missedExpected,
            'unexpected_actual' => $this->unexpectedActual,
            'error' => $this->error,
        ];
    }
}
