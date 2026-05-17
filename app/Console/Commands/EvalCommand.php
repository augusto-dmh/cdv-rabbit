<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eval\EvalResult;
use App\Services\Eval\GoldenFixture;
use App\Services\Eval\LlmJudge;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\OpenAiReviewer;
use App\Services\Llm\PromptBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * `rabbit:eval` — golden-PR quality eval harness (Phase 7, W7-T1; v2 wired in ADR 0007 §1).
 *
 * For every loaded golden fixture, runs the configured provider's review
 * pipeline against the fixture diff, feeds the resulting Findings into
 * LlmJudge (cross-provider by default — AC41), then emits a per-cell table
 * and a structured JSON log row to storage/logs/eval/.
 *
 * Schema branches:
 * - v1: single call to `reviewDiff()`; the resulting comments are flattened
 *   into Findings.
 * - v2: `reviewDiffV2()` produces a DraftReviewDto; `critiqueDraft()` then
 *   verdicts each Finding. Only approved Findings reach the judge — the
 *   same filter the production CommentPoster applies, so the eval recall
 *   reflects what would land on a PR.
 *
 * Exit codes:
 * - 0: every cell met both thresholds (or no fixtures were present).
 * - 1: at least one cell fell below --threshold-recall or --threshold-precision.
 * - 2: --schema=[unknown] was requested.
 */
final class EvalCommand extends Command
{
    protected $signature = 'rabbit:eval
        {--provider=* : One or more LLM providers to evaluate (anthropic, openai). Defaults to all known providers.}
        {--schema=v1 : Review result schema version to evaluate (v1 or v2).}
        {--threshold-recall=0.7 : Minimum recall per cell.}
        {--threshold-precision=0.5 : Minimum precision per cell.}
        {--corpus= : Override the golden corpus root directory.}
        {--cell-delay=3 : Seconds to sleep between cells to keep provider TPM/RPM under control (ADR 0007 §6). Set 0 to disable.}
    ';

    protected $description = 'Run the golden-PR review-quality eval harness across (provider x schema) cells.';

    public function __construct(
        private readonly Container $container,
        private readonly LlmJudge $judge,
        private readonly PromptBuilder $promptBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $schema = (string) $this->option('schema');

        if (! in_array($schema, ['v1', 'v2'], true)) {
            $this->error("rabbit:eval: unknown --schema=[{$schema}]. Supported: v1, v2.");

            return 2;
        }

        if (! (bool) config('cdv-rabbit.eval.cross_provider_judge', true)) {
            $this->warn('rabbit:eval: cross_provider_judge=false — judge uses same provider as reviewer; AC41 bias control disabled.');
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
        $cellDelaySeconds = max(0, (int) $this->option('cell-delay'));
        $cellsRun = 0;
        $totalCells = count($fixtures) * count($providers);

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
                }

                if ($result->error === null
                    && ($result->recall < $thresholdRecall || $result->precision < $thresholdPrecision)) {
                    $failed = true;
                }

                $cellsRun++;
                if ($cellDelaySeconds > 0 && $cellsRun < $totalCells) {
                    sleep($cellDelaySeconds);
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

            $actualFindings = $schema === 'v2'
                ? $this->runV2($driver, $fixture)
                : $this->runV1($driver, $fixture);

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

    /** @return list<array<string, mixed>> */
    private function runV1(LlmDriverInterface $driver, GoldenFixture $fixture): array
    {
        $review = $driver->reviewDiff(
            systemPrompt: $driver->getSystemPrompt(),
            toolSchema: $driver->getToolSchema(),
            userMessage: $this->buildUserMessage($fixture),
        );

        return $this->extractFindings($review);
    }

    /**
     * Run the v2 draft → critique pipeline against the fixture diff and return
     * only the critic-approved Findings — the same filter `CommentPoster::postV2`
     * applies in production. Wraps the diff via PromptBuilder so the LLM sees
     * the `+[L<N>]` line annotations and `<pr_metadata>` envelope that production
     * uses.
     *
     * @return list<array<string, mixed>>
     */
    private function runV2(LlmDriverInterface $driver, GoldenFixture $fixture): array
    {
        $reviewUserMessage = $this->promptBuilder->wrap(
            $fixture->diff,
            [
                'title' => (string) ($fixture->prMetadata['title'] ?? $fixture->fixtureId),
                'branch' => (string) ($fixture->prMetadata['branch'] ?? ''),
                'author' => (string) ($fixture->prMetadata['author'] ?? ''),
            ],
            annotateLines: true,
        );

        $draft = $driver->reviewDiffV2(
            systemPrompt: $driver->getReviewSystemPromptForVersion('v2'),
            toolSchema: $driver->getReviewToolSchemaForVersion('v2'),
            userMessage: $reviewUserMessage,
        );

        $criticUserMessage = "<diff>\n".$this->xmlEscape($fixture->diff)."\n</diff>\n<draft>\n"
            .json_encode($draft->toCriticInputArray(), JSON_PRETTY_PRINT)."\n</draft>";

        $critique = $driver->critiqueDraft(
            systemPrompt: $driver->getCriticSystemPrompt(),
            toolSchema: $driver->getCriticToolSchema(),
            userMessage: $criticUserMessage,
        );

        return $this->extractFindingsFromDraft($draft, $critique->approvedFindingIndices());
    }

    /**
     * @param  list<int>  $approvedIndices
     * @return list<array<string, mixed>>
     */
    private function extractFindingsFromDraft(DraftReviewDto $draft, array $approvedIndices): array
    {
        $out = [];
        foreach ($approvedIndices as $index) {
            if (! isset($draft->findings[$index])) {
                continue;
            }
            $finding = $draft->findings[$index];
            assert($finding instanceof ReviewFindingDto);
            $out[] = [
                'path' => $finding->path,
                'line' => $finding->line,
                'severity' => $finding->severity,
                'message' => $finding->message,
                'category' => $finding->category,
            ];
        }

        return $out;
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
