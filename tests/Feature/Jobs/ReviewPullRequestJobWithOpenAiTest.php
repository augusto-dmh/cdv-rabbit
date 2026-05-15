<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\LlmReviewException;
use App\Services\Review\CostReservationInterface;
use App\Support\OpenAiHeaderBag;
use App\Support\RetryDecision;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCostReservation;

// ---------------------------------------------------------------------------
// Helpers (mirrors ReviewPullRequestJobTest helpers, scoped to OpenAI)
// ---------------------------------------------------------------------------

function setupOpenAiWorkspace(bool $killSwitch = false): array
{
    $workspace = Workspace::factory()->create([
        'kill_switch_enabled' => $killSwitch,
        'llm_provider' => 'openai',
    ]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    return [$workspace, $repository];
}

function dispatchOpenAiJob(Workspace $workspace, Repository $repository, string $sha = 'abc123'): void
{
    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 42,
        headSha: $sha,
    );

    app()->call([$job, 'handle']);
}

function bindOpenAiFakeHttp(string $state = 'OPEN', string $sha = 'abc123', ?string $diff = null): void
{
    Http::fake([
        '*/pullrequests/42' => Http::response([
            'state' => $state,
            'title' => 'Add feature X',
            'source' => ['commit' => ['hash' => $sha], 'branch' => ['name' => 'feature/x']],
            'author' => ['display_name' => 'dev'],
        ]),
        '*/pullrequests/42/diffstat' => Http::response([
            'values' => [['lines_added' => 10, 'lines_removed' => 5]],
        ]),
        '*/pullrequests/42/diff' => Http::response($diff ?? "diff --git a/app/Foo.php b/app/Foo.php\n+class Foo {}"),
        '*/pullrequests/42/comments' => Http::response(['id' => 999]),
        '*' => Http::response([]),
    ]);

    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create(['llm_provider' => 'openai']);

        return new BitbucketClient($ws);
    });
}

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// AC27: OpenAI workspace happy path posts review
// ---------------------------------------------------------------------------

it('AC27: OpenAI workspace happy path — review posted successfully', function (): void {
    [$workspace, $repository] = setupOpenAiWorkspace();

    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));
    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag(requestId: 'openai-req-happy'));

    bindFakeLlm();
    bindOpenAiFakeHttp();

    dispatchOpenAiJob($workspace, $repository);

    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Posted);
});

// ---------------------------------------------------------------------------
// AC29: RetryWithBackoff re-throws for Horizon retry on OpenAI workspace
// ---------------------------------------------------------------------------

it('AC29: OpenAI RetryWithBackoff error re-throws for Horizon retry', function (): void {
    [$workspace, $repository] = setupOpenAiWorkspace();

    $retryException = new LlmReviewException(
        message: 'Rate limited by OpenAI',
        retryDecision: RetryDecision::RetryWithBackoff,
    );

    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));
    bindFakeLlm(fn () => throw $retryException);
    bindOpenAiFakeHttp();

    expect(function () use ($workspace, $repository): void {
        dispatchOpenAiJob($workspace, $repository);
    })->toThrow(LlmReviewException::class);
});

// ---------------------------------------------------------------------------
// AC30: Terminal error marks review failed on OpenAI workspace
// ---------------------------------------------------------------------------

it('AC30: OpenAI Terminal error marks review failed and does not re-throw', function (): void {
    [$workspace, $repository] = setupOpenAiWorkspace();

    $terminalException = new LlmReviewException(
        message: 'Context length exceeded',
        retryDecision: RetryDecision::Terminal,
    );

    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));
    bindFakeLlm(fn () => throw $terminalException);
    bindOpenAiFakeHttp();

    expect(function () use ($workspace, $repository): void {
        dispatchOpenAiJob($workspace, $repository);
    })->not->toThrow(Throwable::class);

    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Failed);
});
