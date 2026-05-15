<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Logging\RedactingProcessor;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\LlmReviewException;
use App\Services\Review\CostReservationInterface;
use App\Support\RetryDecision;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Fakes\FakeCostReservation;

// ---------------------------------------------------------------------------
// Helpers (local to this file)
// ---------------------------------------------------------------------------

function logTestDiff(): string
{
    return <<<'DIFF'
    diff --git a/app/Foo.php b/app/Foo.php
    index 0000000..1111111 100644
    --- a/app/Foo.php
    +++ b/app/Foo.php
    @@ -1,3 +1,4 @@
     <?php
    +class Foo {}
    DIFF;
}

function logTestReviewResultDto(): ReviewResultDto
{
    return new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Looks good.', riskLevel: 'low'),
        comments: [
            new ReviewCommentDto(path: 'app/Foo.php', line: 2, severity: 'nit', message: 'Add docblock.'),
        ],
        inputTokens: 1000,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 200,
        requestId: 'req_test',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 100,
    );
}

function logTestSetupWorkspaceAndRepo(): array
{
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    return [$workspace, $repository];
}

function logTestBindFakeCostReservation(bool $granted = true): FakeCostReservation
{
    $fake = new FakeCostReservation(granted: $granted);
    app()->bind(CostReservationInterface::class, fn () => $fake);

    return $fake;
}

function logTestBindFakeLlm(?Closure $callback = null): void
{
    $cb = $callback ?? fn () => logTestReviewResultDto();

    app()->bind(LlmDriverInterface::class, fn () => new class($cb) implements LlmDriverInterface
    {
        public function __construct(private Closure $cb) {}

        public function getSystemPrompt(): string
        {
            return '';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): ReviewResultDto
        {
            return ($this->cb)($systemPrompt, $toolSchema, $userMessage);
        }
    });

    bindFakeLlmFactory();
}

function logTestBindFakeClaudeReviewer(): void
{
    app()->bind(ClaudeReviewer::class, fn () => new class
    {
        public function getSystemPrompt(): string
        {
            return 'system prompt';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): ReviewResultDto
        {
            return logTestReviewResultDto();
        }
    });
}

function logTestBindFakeTelemetry(): void
{
    app()->bind(LlmCallTelemetry::class, fn () => new class
    {
        public function record(mixed ...$args): mixed
        {
            return null;
        }
    });
}

function logTestFakeHttp(
    string $state = 'OPEN',
    string $sha = 'abc123',
    ?string $diff = null,
    int $added = 10,
    int $removed = 5,
): void {
    Http::fake([
        '*/pullrequests/42' => Http::response([
            'state' => $state,
            'title' => 'Add feature X',
            'source' => [
                'commit' => ['hash' => $sha],
                'branch' => ['name' => 'feature/x'],
            ],
            'author' => ['display_name' => 'dev'],
        ]),
        '*/pullrequests/42/diffstat' => Http::response([
            'values' => [['lines_added' => $added, 'lines_removed' => $removed]],
        ]),
        '*/pullrequests/42/diff' => Http::response($diff ?? logTestDiff()),
        '*/pullrequests/42/comments' => Http::response(['id' => 999]),
        '*' => Http::response([]),
    ]);

    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });
}

function logTestDispatchJob(Workspace $workspace, Repository $repository, string $sha = 'abc123'): void
{
    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 42,
        headSha: $sha,
    );

    app()->call([$job, 'handle']);
}

/**
 * Tap a TestHandler into the cdv-rabbit-reviews channel and return it.
 */
function tapReviewsChannel(): TestHandler
{
    $handler = new TestHandler(Level::Info, bubble: false);

    Log::channel('cdv-rabbit-reviews')->getLogger()->pushHandler($handler);

    return $handler;
}

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('posted review writes one JSON log line with all required fields', function (): void {
    [$workspace, $repository] = logTestSetupWorkspaceAndRepo();

    logTestBindFakeLlm();
    logTestBindFakeCostReservation();
    logTestBindFakeClaudeReviewer();
    logTestBindFakeTelemetry();
    logTestFakeHttp();

    $handler = tapReviewsChannel();

    logTestDispatchJob($workspace, $repository);

    expect($handler->getRecords())->toHaveCount(1);

    $record = $handler->getRecords()[0];
    $context = $record->context;

    expect($context)->toHaveKeys([
        'correlation_id',
        'workspace_id',
        'repository_id',
        'pull_request_number',
        'head_sha',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd_cents',
        'secrets_redacted',
        'error_class',
        'error_message',
        'llm_calls_count',
    ]);

    expect($context['status'])->toBe(ReviewStatus::Posted->value)
        ->and($context['correlation_id'])->not->toBeNull()
        ->and(strlen((string) $context['head_sha']))->toBeLessThanOrEqual(8)
        ->and($context['prompt_tokens'])->toBeGreaterThan(0);
});

it('failed review writes log line with error_class and error_message but no stack trace', function (): void {
    [$workspace, $repository] = logTestSetupWorkspaceAndRepo();

    $terminalException = new LlmReviewException(
        message: 'Bad request from API',
        retryDecision: RetryDecision::Terminal,
    );

    logTestBindFakeLlm(fn () => throw $terminalException);
    logTestBindFakeCostReservation();
    logTestBindFakeClaudeReviewer();
    logTestBindFakeTelemetry();
    logTestFakeHttp();

    $handler = tapReviewsChannel();

    logTestDispatchJob($workspace, $repository);

    expect($handler->getRecords())->toHaveCount(1);

    $context = $handler->getRecords()[0]->context;

    expect($context['status'])->toBe(ReviewStatus::Failed->value)
        ->and($context['error_class'])->not->toBeNull()
        ->and($context['error_message'])->toBeString();

    // No stack trace in context
    $contextJson = json_encode($context);
    expect($contextJson)->not->toContain('#0')
        ->and($contextJson)->not->toContain('Stack trace');
});

it('RedactingProcessor strips forbidden keys from context', function (): void {
    $processor = new RedactingProcessor;

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'test message',
        context: [
            'diff' => 'diff --git a/foo.php ...',
            'workspace_id' => 42,
            'patch' => 'some patch',
            'content' => 'file content',
        ],
        extra: [
            'hunk' => 'hunk data',
            'request_id' => 'req_123',
        ],
    );

    $result = $processor($record);

    expect($result->context)->not->toHaveKey('diff')
        ->and($result->context)->not->toHaveKey('patch')
        ->and($result->context)->not->toHaveKey('content')
        ->and($result->context)->toHaveKey('workspace_id')
        ->and($result->extra)->not->toHaveKey('hunk')
        ->and($result->extra)->toHaveKey('request_id');
});

it('log line context parses cleanly as JSON with all scalar values', function (): void {
    [$workspace, $repository] = logTestSetupWorkspaceAndRepo();

    logTestBindFakeLlm();
    logTestBindFakeCostReservation();
    logTestBindFakeClaudeReviewer();
    logTestBindFakeTelemetry();
    logTestFakeHttp();

    $handler = tapReviewsChannel();

    logTestDispatchJob($workspace, $repository);

    $context = $handler->getRecords()[0]->context;

    $json = json_encode($context);
    expect($json)->toBeString();

    $decoded = json_decode($json, true);
    expect($decoded)->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});
