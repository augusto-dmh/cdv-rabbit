<?php

declare(strict_types=1);

namespace App\Services\Eval;

use InvalidArgumentException;
use RuntimeException;

/**
 * Cross-provider LLM-as-judge for `rabbit:eval`.
 *
 * AC41: the judge for a review produced by provider X is always provider !=X.
 * Rotation map is `anthropic <-> openai`. The judge call itself is routed
 * through JudgeLlmCallerInterface (LaravelAiJudgeLlmCaller in production,
 * a fake in tests) so this class has no coupling to LlmDriverFactory.
 */
final class LlmJudge
{
    private const ROTATION_CROSS = [
        'anthropic' => 'openai',
        'openai' => 'anthropic',
    ];

    private const ROTATION_SAME = [
        'anthropic' => 'anthropic',
        'openai' => 'openai',
    ];

    public function __construct(
        private readonly JudgeLlmCallerInterface $caller,
        private readonly string $judgePromptPath,
        private readonly bool $crossProviderJudge = true,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $actualFindings
     * @param  list<array<string, mixed>>  $expectedFindings
     * @return array{
     *     matched: list<array{actual_idx: int, expected_idx: int}>,
     *     missed: list<int>,
     *     unexpected: list<int>,
     *     recall: float,
     *     precision: float,
     *     judge_provider: string
     * }
     */
    public function judge(array $actualFindings, array $expectedFindings, string $reviewProvider): array
    {
        $judgeProvider = $this->pickJudgeProvider($reviewProvider);

        if ($actualFindings === [] && $expectedFindings === []) {
            return $this->result([], [], [], $judgeProvider);
        }

        $verdict = $this->callJudge($actualFindings, $expectedFindings, $judgeProvider);

        $matches = $this->normaliseMatches($verdict['matches'] ?? []);
        $missed = $this->normaliseIndices($verdict['missed_expected'] ?? []);
        $unexpected = $this->normaliseIndices($verdict['unexpected_actual'] ?? []);

        return $this->result($matches, $missed, $unexpected, $judgeProvider, $expectedFindings, $actualFindings);
    }

    /**
     * Pick the judge provider for a review produced by $reviewProvider.
     *
     * Default behaviour is cross-provider rotation (AC41 — `anthropic <-> openai`)
     * so same-family sycophancy is avoided. When `cdv-rabbit.eval.cross_provider_judge`
     * is false (operator escape hatch per ADR 0007 §2), the judge uses the same
     * provider as the reviewer — useful when only one provider's credentials are
     * available, at the cost of losing the cross-family bias control.
     */
    public function pickJudgeProvider(string $reviewProvider): string
    {
        $rotation = $this->crossProviderJudge ? self::ROTATION_CROSS : self::ROTATION_SAME;

        if (! isset($rotation[$reviewProvider])) {
            throw new InvalidArgumentException(
                "LlmJudge: unsupported review provider [{$reviewProvider}]. Known providers: ".implode(',', array_keys($rotation))
            );
        }

        return $rotation[$reviewProvider];
    }

    /**
     * @param  list<array<string, mixed>>  $actualFindings
     * @param  list<array<string, mixed>>  $expectedFindings
     * @return array<string, mixed>
     */
    private function callJudge(array $actualFindings, array $expectedFindings, string $judgeProvider): array
    {
        if (! file_exists($this->judgePromptPath)) {
            throw new RuntimeException("LlmJudge: judge prompt not found at [{$this->judgePromptPath}].");
        }

        $systemPrompt = (string) file_get_contents($this->judgePromptPath);

        $userMessage = json_encode([
            'expected_findings' => $expectedFindings,
            'actual_findings' => $actualFindings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $raw = $this->caller->complete($judgeProvider, $systemPrompt, (string) $userMessage);

        $parsed = $this->parseJson($raw);

        if ($parsed === null) {
            throw new RuntimeException('LlmJudge: judge response was not valid JSON: '.substr($raw, 0, 200));
        }

        return $parsed;
    }

    /** @return array<string, mixed>|null */
    private function parseJson(string $raw): ?array
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<array{actual_idx: int, expected_idx: int}>
     */
    private function normaliseMatches(mixed $matches): array
    {
        if (! is_array($matches)) {
            return [];
        }

        $out = [];
        foreach ($matches as $m) {
            if (is_array($m) && isset($m['actual_idx'], $m['expected_idx'])) {
                $out[] = [
                    'actual_idx' => (int) $m['actual_idx'],
                    'expected_idx' => (int) $m['expected_idx'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function normaliseIndices(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_map('intval', $list));
    }

    /**
     * @param  list<array{actual_idx: int, expected_idx: int}>  $matches
     * @param  list<int>  $missed
     * @param  list<int>  $unexpected
     * @param  list<array<string, mixed>>  $expectedFindings
     * @param  list<array<string, mixed>>  $actualFindings
     * @return array{
     *     matched: list<array{actual_idx: int, expected_idx: int}>,
     *     missed: list<int>,
     *     unexpected: list<int>,
     *     recall: float,
     *     precision: float,
     *     judge_provider: string
     * }
     */
    private function result(
        array $matches,
        array $missed,
        array $unexpected,
        string $judgeProvider,
        array $expectedFindings = [],
        array $actualFindings = [],
    ): array {
        $expectedCount = count($expectedFindings);
        $actualCount = count($actualFindings);
        $matchCount = count($matches);

        $recall = $expectedCount === 0 ? 1.0 : $matchCount / $expectedCount;
        $precision = $actualCount === 0 ? 1.0 : $matchCount / $actualCount;

        return [
            'matched' => $matches,
            'missed' => $missed,
            'unexpected' => $unexpected,
            'recall' => round($recall, 4),
            'precision' => round($precision, 4),
            'judge_provider' => $judgeProvider,
        ];
    }
}
