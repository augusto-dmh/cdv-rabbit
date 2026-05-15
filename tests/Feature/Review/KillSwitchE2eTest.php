<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Review\CostReservationInterface;
use App\Support\AnthropicHeaderBag;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCostReservation;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('AC8: kill switch on — job marks review skipped, no LLM call', function (): void {
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => true]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $llmCalled = false;
    app()->bind(LlmDriverInterface::class, fn () => new class($llmCalled) implements LlmDriverInterface
    {
        public function __construct(private bool &$called) {}

        public function getSystemPrompt(): string
        {
            return '';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): never
        {
            $this->called = true;
            throw new RuntimeException('LLM must not be called when kill switch is on');
        }
    });
    bindFakeLlmFactory();

    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    Http::fake(['*' => Http::response([])]);
    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 99,
        headSha: 'ks001',
    );

    app()->call([$job, 'handle']);

    expect($llmCalled)->toBeFalse();

    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review)->not->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::Skipped);
});

test('AC8: kill switch toggled on mid-flight stops next job dispatch', function (): void {
    // First job runs with kill switch OFF — succeeds (or at least doesn't abort early).
    $workspaceOff = Workspace::factory()->create(['kill_switch_enabled' => false]);
    app(WorkspaceContext::class)->bind($workspaceOff->id);
    $repositoryOff = Repository::factory()->forWorkspace($workspaceOff)->create();

    $llmCalledOff = false;
    app()->bind(LlmDriverInterface::class, fn () => new class($llmCalledOff) implements LlmDriverInterface
    {
        public function __construct(private bool &$called) {}

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
            $this->called = true;

            return new ReviewResultDto(
                summary: new ReviewSummaryDto(overview: 'ok', riskLevel: 'low'),
                comments: [],
                inputTokens: 100,
                cacheCreationInputTokens: 0,
                cacheReadInputTokens: 0,
                outputTokens: 10,
                requestId: 'req_ks_off',
                rateLimitTokensRemaining: null,
                rateLimitTokensReset: null,
                durationMs: 50,
            );
        }
    });
    bindFakeLlmFactory();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    Http::fake([
        '*/pullrequests/55' => Http::response([
            'state' => 'OPEN',
            'title' => 'KS off PR',
            'source' => ['commit' => ['hash' => 'ks_off'], 'branch' => ['name' => 'feat']],
            'author' => ['display_name' => 'dev'],
        ]),
        '*/pullrequests/55/diffstat' => Http::response([
            'values' => [['lines_added' => 5, 'lines_removed' => 0]],
        ]),
        '*/pullrequests/55/diff' => Http::response("diff --git a/app/F.php b/app/F.php\n+class F {}"),
        '*/pullrequests/55/comments' => Http::response(['id' => 2001]),
        '*' => Http::response([]),
    ]);

    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });

    app()->instance(AnthropicHeaderBag::class, new AnthropicHeaderBag(requestId: 'req_ks'));
    app()->bind(LlmCallTelemetry::class, fn () => new class
    {
        public function record(mixed ...$args): mixed
        {
            return null;
        }
    });

    $jobOff = new ReviewPullRequestJob(
        workspaceId: $workspaceOff->id,
        repositoryId: $repositoryOff->id,
        pullRequestNumber: 55,
        headSha: 'ks_off',
    );
    app()->call([$jobOff, 'handle']);
    app(WorkspaceContext::class)->clear();

    // Now flip kill switch ON for a second workspace.
    $workspaceOn = Workspace::factory()->create(['kill_switch_enabled' => true]);
    app(WorkspaceContext::class)->bind($workspaceOn->id);
    $repositoryOn = Repository::factory()->forWorkspace($workspaceOn)->create();

    $llmCalledOn = false;
    app()->bind(LlmDriverInterface::class, fn () => new class($llmCalledOn) implements LlmDriverInterface
    {
        public function __construct(private bool &$called) {}

        public function getSystemPrompt(): string
        {
            return '';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): never
        {
            $this->called = true;
            throw new RuntimeException('Kill switch should have prevented this');
        }
    });
    bindFakeLlmFactory();

    $jobOn = new ReviewPullRequestJob(
        workspaceId: $workspaceOn->id,
        repositoryId: $repositoryOn->id,
        pullRequestNumber: 56,
        headSha: 'ks_on',
    );
    app()->call([$jobOn, 'handle']);

    expect($llmCalledOn)->toBeFalse();

    $skippedReview = Review::where('workspace_id', $workspaceOn->id)->first();
    expect($skippedReview->status)->toBe(ReviewStatus::Skipped);
});
