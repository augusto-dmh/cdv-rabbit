<?php

declare(strict_types=1);

use App\Services\Eval\JudgeLlmCallerInterface;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\OpenAiReviewer;
use Tests\Fakes\StubsV2LlmDriverMethods;

function makeFakeReviewer(ReviewResultDto $result): LlmDriverInterface
{
    return new class($result) implements LlmDriverInterface
    {
        use StubsV2LlmDriverMethods;

        public function __construct(private readonly ReviewResultDto $result) {}

        public function reviewDiff(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): ReviewResultDto
        {
            return $this->result;
        }

        public function getSystemPrompt(): string
        {
            return 'fake-system-prompt';
        }

        public function getToolSchema(): array
        {
            return ['name' => 'review_result', 'input_schema' => ['type' => 'object']];
        }
    };
}

function bindAllFakeReviewers(ReviewResultDto $result): void
{
    $reviewer = makeFakeReviewer($result);
    app()->instance(ClaudeReviewer::class, $reviewer);
    app()->instance(OpenAiReviewer::class, $reviewer);
}

function bindFakeJudge(array $verdict): void
{
    app()->instance(JudgeLlmCallerInterface::class, new class($verdict) implements JudgeLlmCallerInterface
    {
        public function __construct(private readonly array $verdict) {}

        public function complete(string $provider, string $systemPrompt, string $userMessage): string
        {
            return json_encode($this->verdict);
        }
    });
}

function fakeReviewResult(): ReviewResultDto
{
    return new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'fake', riskLevel: 'low'),
        comments: [
            new ReviewCommentDto(path: 'app/Concerns/EnsuresSameTenant.php', line: 5, severity: 'medium', message: 'consider abort(404)'),
        ],
        inputTokens: 0,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 0,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 1,
    );
}

beforeEach(function (): void {
    bindAllFakeReviewers(fakeReviewResult());
    bindFakeJudge([
        'matches' => [['actual_idx' => 0, 'expected_idx' => 0]],
        'missed_expected' => [1, 2, 3],
        'unexpected_actual' => [],
    ]);
});

it('runs rabbit:eval against the golden corpus and writes a structured log', function (): void {
    $logDir = storage_path('logs/eval');
    foreach (glob($logDir.'/*.jsonl') ?: [] as $f) {
        @unlink($f);
    }

    $this->artisan('rabbit:eval', [
        '--provider' => ['anthropic'],
        '--schema' => 'v1',
        '--threshold-recall' => 0.0,
        '--threshold-precision' => 0.0,
    ])->assertExitCode(0);

    $logs = glob($logDir.'/*.jsonl') ?: [];
    expect($logs)->not->toBeEmpty();
});

it('fails when recall is below threshold', function (): void {
    bindFakeJudge([
        'matches' => [],
        'missed_expected' => [0, 1, 2, 3],
        'unexpected_actual' => [0],
    ]);

    $this->artisan('rabbit:eval', [
        '--provider' => ['anthropic'],
        '--schema' => 'v1',
        '--threshold-recall' => 0.7,
        '--threshold-precision' => 0.0,
    ])->assertExitCode(1);
});

it('accepts --schema=v2 (ADR 0007 §1 wired the v2 path)', function (): void {
    $emptyDir = sys_get_temp_dir().'/golden-empty-'.uniqid();
    mkdir($emptyDir);

    try {
        $this->artisan('rabbit:eval', [
            '--schema' => 'v2',
            '--corpus' => $emptyDir,
        ])->assertExitCode(0);
    } finally {
        @rmdir($emptyDir);
    }
});

it('exits 2 when --schema is unknown', function (): void {
    $this->artisan('rabbit:eval', [
        '--schema' => 'v9',
    ])->assertExitCode(2);
});

it('exits 0 with a warning when no golden fixtures are found', function (): void {
    $emptyDir = sys_get_temp_dir().'/golden-empty-'.uniqid();
    mkdir($emptyDir);

    try {
        $this->artisan('rabbit:eval', [
            '--provider' => ['anthropic'],
            '--corpus' => $emptyDir,
        ])->assertExitCode(0);
    } finally {
        @rmdir($emptyDir);
    }
});
