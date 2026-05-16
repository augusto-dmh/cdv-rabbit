<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eval\EvalResult;
use App\Services\Eval\GoldenFixture;
use App\Services\Eval\LlmJudge;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\OpenAiReviewer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * `rabbit:eval` — golden-PR quality eval harness (Phase 7, W7-T1).
 *
 * For every loaded golden fixture, runs the configured provider's v1 review
 * pipeline against the fixture diff, feeds the resulting Findings into
 * LlmJudge (which routes to the opposite provider — AC41), then emits a
 * per-cell table and a structured JSON log row to storage/logs/eval/.
 *
 * Exit codes:
 * - 0: every cell met both thresholds (or no fixtures were present).
 * - 1: at least one cell fell below --threshold-recall or --threshold-precision.
 * - 2: --schema=v2 was requested (schema v2 is W7-T2; not yet implemented).
 */
final class EvalCommand extends Command
{
    protected $signature = 'rabbit:eval
        {--provider=* : One or more LLM providers to evaluate (anthropic, openai). Defaults to all known providers.}
        {--schema=v1 : Review result schema version to evaluate (v1 only until W7-T2 lands).}
        {--threshold-recall=0.7 : Minimum recall per cell.}
        {--threshold-precision=0.5 : Minimum precision per cell.}
        {--corpus= : Override the golden corpus root directory.}
    ';

    protected $description = 'Run the golden-PR review-quality eval harness across (provider x schema) cells.';

    public function __construct(
        private readonly Container $container,
        private readonly LlmJudge $judge,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $schema = (string) $this->option('schema');

        if ($schema === 'v2') {
            $this->error('rabbit:eval: schema v2 not yet implemented (W7-T2 scope). Run with --schema=v1.');

            return 2;
        }

        if ($schema !== 'v1') {
            $this->error("rabbit:eval: unknown --schema=[{$schema}]. Supported: v1.");

            return 2;
        }

        $providers = $this->resolveProviders();
        $thresholdRecall = (float) $this->option('threshold-recall');
        $thresholdPrecision = (float) $this->option('threshold-precision');

        $corpusRoot = (string) ($this->option('corpus') ?? base_path('tests/Eval/golden'));
        $fixtures = GoldenFixture::loadAll($corpusRoot);

        if ($fixtures === []) {
            $this->warn("rabbit:eval: no golden fixtures found under [{$corpusRoot}]. Add fixtures per docs/playbooks/golden-prs.md.");

            return self::SUCCESS;
        }

        $rows = [];
        $logRows = [];
        $failed = false;

        foreach ($fixtures as $fixture) {
            foreach ($providers as $provider) {
                $result = $this->evaluateCell($fixture, $provider, $schema);

                $rows[] = [
                    $fixture->fixtureId,
                    $provider,
                    $schema,
                    $result->error !== null ? 'ERROR' : number_format($result->recall, 3),
                    $result->error !== null ? '-' : number_format($result->precision, 3),
                    count($result->actualFindings),
                    $result->error ?? 'ok',
                ];

                $logRows[] = $result->toArray();

                if ($result->error !== null) {
                    $failed = true;

                    continue;
                }

                if ($result->recall < $thresholdRecall || $result->precision < $thresholdPrecision) {
                    $failed = true;
                }
            }
        }

        $this->table(
            ['Fixture', 'Provider', 'Schema', 'Recall', 'Precision', '#Found', 'Notes'],
            $rows,
        );

        $this->writeLog($logRows);

        if ($failed) {
            $this->error("rabbit:eval: one or more cells below thresholds (recall>={$thresholdRecall}, precision>={$thresholdPrecision}).");

            return self::FAILURE;
        }

        $this->info('rabbit:eval: all cells met thresholds.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveProviders(): array
    {
        $raw = $this->option('provider');
        $list = is_array($raw) ? $raw : [];
        $list = array_values(array_filter(array_map('strval', $list), fn (string $v) => $v !== ''));

        if ($list === []) {
            return ['anthropic', 'openai'];
        }

        foreach ($list as $p) {
            if (! in_array($p, ['anthropic', 'openai'], true)) {
                $this->warn("rabbit:eval: ignoring unknown provider [{$p}]");
            }
        }

        return array_values(array_intersect($list, ['anthropic', 'openai']));
    }

    private function evaluateCell(GoldenFixture $fixture, string $provider, string $schema): EvalResult
    {
        try {
            $driver = $this->resolveDriver($provider);
            $review = $driver->reviewDiff(
                systemPrompt: $driver->getSystemPrompt(),
                toolSchema: $driver->getToolSchema(),
                userMessage: $this->buildUserMessage($fixture),
            );

            $actualFindings = $this->extractFindings($review);
            $verdict = $this->judge->judge($actualFindings, $fixture->expectedFindings, $provider);

            return new EvalResult(
                fixtureId: $fixture->fixtureId,
                provider: $provider,
                schemaVersion: $schema,
                actualFindings: $actualFindings,
                matches: $verdict['matched'],
                missedExpected: $verdict['missed'],
                unexpectedActual: $verdict['unexpected'],
                recall: $verdict['recall'],
                precision: $verdict['precision'],
            );
        } catch (Throwable $e) {
            return new EvalResult(
                fixtureId: $fixture->fixtureId,
                provider: $provider,
                schemaVersion: $schema,
                actualFindings: [],
                matches: [],
                missedExpected: [],
                unexpectedActual: [],
                recall: 0.0,
                precision: 0.0,
                error: $e->getMessage(),
            );
        }
    }

    private function resolveDriver(string $provider): LlmDriverInterface
    {
        return match ($provider) {
            'anthropic' => $this->container->make(ClaudeReviewer::class),
            'openai' => $this->container->make(OpenAiReviewer::class),
            default => throw new \InvalidArgumentException("rabbit:eval: unsupported provider [{$provider}]"),
        };
    }

    private function buildUserMessage(GoldenFixture $fixture): string
    {
        $meta = $fixture->prMetadata;
        $title = (string) ($meta['title'] ?? $fixture->fixtureId);
        $summary = (string) ($meta['summary'] ?? '');

        $metadataXml = "<pr_metadata>\n  <title>{$this->xmlEscape($title)}</title>\n  <summary>{$this->xmlEscape($summary)}</summary>\n</pr_metadata>";

        return $metadataXml."\n<diff>\n".$fixture->diff."\n</diff>";
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return list<array<string, mixed>> */
    private function extractFindings(ReviewResultDto $review): array
    {
        $out = [];
        foreach ($review->comments as $comment) {
            $out[] = [
                'path' => $comment->path,
                'line' => $comment->line,
                'severity' => $comment->severity,
                'message' => $comment->message,
                'category' => 'maintainability',
            ];
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $logRows */
    private function writeLog(array $logRows): void
    {
        $dir = storage_path('logs/eval');
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir.'/'.date('Ymd_His').'-rabbit-eval.jsonl';
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return;
        }

        foreach ($logRows as $row) {
            fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES)."\n");
        }
        fclose($handle);

        $this->line("rabbit:eval: structured log written to {$path}");
    }
}
