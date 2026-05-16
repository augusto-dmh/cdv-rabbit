<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Logging\RedactingProcessor;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Review\CostReservationInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Fakes\FakeCostReservation;
use Tests\Fakes\StubsV2LlmDriverMethods;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function phase5ReviewResultDto(): ReviewResultDto
{
    return new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Looks good.', riskLevel: 'low'),
        comments: [
            new ReviewCommentDto(path: 'app/Billing.php', line: 3, severity: 'nit', message: 'Add docblock.'),
        ],
        inputTokens: 500,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 100,
        requestId: 'req_phase5',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 50,
    );
}

function phase5FakeHttp(): void
{
    Http::fake([
        '*/pullrequests/1' => Http::response([
            'state' => 'OPEN',
            'title' => 'Add billing service',
            'source' => [
                'commit' => ['hash' => 'abc12345'],
                'branch' => ['name' => 'feature/billing'],
            ],
            'author' => ['display_name' => 'dev'],
        ]),
        '*/pullrequests/1/diffstat' => Http::response([
            'values' => [['lines_added' => 10, 'lines_removed' => 0]],
        ]),
        '*/pullrequests/1/diff' => Http::response(
            "diff --git a/app/Billing.php b/app/Billing.php\n".
            "index 0000000..1111111 100644\n--- a/app/Billing.php\n+++ b/app/Billing.php\n".
            "@@ -1,3 +1,4 @@\n <?php\n+class Billing {}\n"
        ),
        '*/pullrequests/1/comments' => Http::response(['id' => 777]),
        '*' => Http::response([]),
    ]);

    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });
}

function phase5BindFakes(): void
{
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    app()->bind(LlmDriverInterface::class, fn () => new class implements LlmDriverInterface
    {
        use StubsV2LlmDriverMethods;

        public function getSystemPrompt(): string
        {
            return '';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): ReviewResultDto
        {
            return phase5ReviewResultDto();
        }
    });
    bindFakeLlmFactory();

    app()->bind(ClaudeReviewer::class, fn () => new class
    {
        public function getSystemPrompt(): string
        {
            return 'system';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): ReviewResultDto
        {
            return phase5ReviewResultDto();
        }
    });

    app()->bind(LlmCallTelemetry::class, fn () => new class
    {
        public function record(mixed ...$args): mixed
        {
            return null;
        }
    });
}

function phase5TapReviewsChannel(): TestHandler
{
    $handler = new TestHandler(Level::Info, bubble: false);
    Log::channel('cdv-rabbit-reviews')->getLogger()->pushHandler($handler);

    return $handler;
}

// ---------------------------------------------------------------------------
// Smoke 1: webhook → orchestrator → posted review → structured log line
// ---------------------------------------------------------------------------

test('Phase5 smoke: posted review emits structured log line with all required fields', function (): void {
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    phase5BindFakes();
    phase5FakeHttp();

    $handler = phase5TapReviewsChannel();

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 1,
        headSha: 'abc12345',
    );
    app()->call([$job, 'handle']);

    $review = Review::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Posted);

    // Structured log must have been emitted
    expect($handler->getRecords())->toHaveCount(1);
    $ctx = $handler->getRecords()[0]->context;

    expect($ctx)->toHaveKeys([
        'correlation_id', 'workspace_id', 'repository_id', 'pull_request_number',
        'head_sha', 'status', 'started_at', 'finished_at', 'duration_ms',
        'prompt_tokens', 'completion_tokens', 'cost_usd_cents',
        'secrets_redacted', 'error_class', 'error_message', 'llm_calls_count',
    ]);

    expect($ctx['status'])->toBe(ReviewStatus::Posted->value)
        ->and($ctx['correlation_id'])->not->toBeNull();

    // LGPD: no diff/code content in log
    $json = json_encode($ctx);
    expect($json)->not->toContain('diff --git')
        ->and($json)->not->toContain('class Billing');

    // A comment was posted
    expect(ReviewComment::withoutWorkspaceScope()->where('review_id', $review->id)->count())->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// Smoke 2: /up returns 200 healthy when all deps faked up
// ---------------------------------------------------------------------------

test('Phase5 smoke: /up returns 200 healthy when all deps are faked healthy', function (): void {
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 200),
        'https://api.anthropic.com/*' => Http::response('', 200),
    ]);

    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andReturn(['supervisor-1']);
    $horizonMock->shouldReceive('zscore')->andReturn((string) time());
    Redis::shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);

    $response = $this->get('/up');

    $response->assertStatus(200)
        ->assertJson(['status' => 'healthy'])
        ->assertJsonPath('checks.db.ok', true)
        ->assertJsonPath('checks.redis.ok', true)
        ->assertJsonPath('checks.horizon.ok', true)
        ->assertJsonPath('checks.bitbucket_api.ok', true)
        ->assertJsonPath('checks.anthropic_api.ok', true);
});

// ---------------------------------------------------------------------------
// Smoke 3: rabbit:purge-stale --dry-run lists candidates, changes nothing
// ---------------------------------------------------------------------------

test('Phase5 smoke: rabbit:purge-stale --dry-run touches nothing', function (): void {
    $workspace = Workspace::factory()->create();
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    app(WorkspaceContext::class)->bind($workspace->id);
    $oldReview = Review::factory()->forRepository($repository)->create([
        'created_at' => now()->subDays(400),
        'updated_at' => now()->subDays(400),
    ]);

    $oldWebhook = WebhookDelivery::factory()->create([
        'repository_id' => $repository->id,
        'created_at' => now()->subDays(100),
    ]);

    $this->artisan('rabbit:purge-stale --dry-run')->assertSuccessful();

    // Nothing soft-deleted
    expect(Review::withoutWorkspaceScope()->find($oldReview->id)->deleted_at)->toBeNull();
    // Nothing hard-deleted
    expect(WebhookDelivery::find($oldWebhook->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Smoke 4: rabbit:lgpd-check exits 0 on healthy env
// ---------------------------------------------------------------------------

test('Phase5 smoke: rabbit:lgpd-check exits 0 on healthy test environment', function (): void {
    $signoffPath = storage_path('app/dpo-signoff-phase5-smoke.json');

    file_put_contents($signoffPath, json_encode([
        'signer' => 'Phase5 DPO',
        'signed_at' => now()->toIso8601String(),
    ]));

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => $signoffPath,
    ]);

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();

    @unlink($signoffPath);
});

// ---------------------------------------------------------------------------
// Smoke 5: RedactingProcessor strips forbidden keys from any log call
// ---------------------------------------------------------------------------

test('Phase5 smoke: RedactingProcessor strips diff/code keys from structured log context', function (): void {
    $processor = new RedactingProcessor;
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'cdv-rabbit-reviews',
        level: Level::Info,
        message: 'test',
        context: [
            'diff' => 'diff --git a/foo.php...',
            'code' => 'class Foo {}',
            'workspace_id' => 42,
        ],
        extra: [],
    );

    $result = $processor($record);

    expect($result->context)->not->toHaveKey('diff')
        ->and($result->context)->not->toHaveKey('code')
        ->and($result->context)->toHaveKey('workspace_id');
});
