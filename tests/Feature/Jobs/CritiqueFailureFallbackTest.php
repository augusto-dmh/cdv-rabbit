<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\LlmReviewException;
use App\Services\Review\CostReservationInterface;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\FileChangeDto;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use App\Services\Scm\ScmDriverFactory;
use App\Support\RetryDecision;
use Illuminate\Support\Collection;
use Tests\Fakes\FakeCostReservation;
use Tests\Fakes\FakeLlmDriver;

// ---------------------------------------------------------------------------
// Local helpers (self-contained — no dependency on V2Test helpers)
// ---------------------------------------------------------------------------

function fallbackMakeFinding(int $index): ReviewFindingDto
{
    return new ReviewFindingDto(
        path: "app/Fallback{$index}.php",
        line: $index + 1,
        severity: 'medium',
        category: 'correctness',
        message: "Fallback finding {$index}.",
        suggestion: null,
    );
}

/**
 * @param  list<ReviewFindingDto>  $findings
 * @param  list<ReviewNitpickDto>  $nitpicks
 */
function fallbackMakeDraft(array $findings, array $nitpicks = []): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Review complete.',
            riskLevel: 'medium',
            walkthrough: 'Walkthrough text.',
            filesAnalyzed: [],
        ),
        findings: $findings,
        nitpicks: $nitpicks,
        inputTokens: 500,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 100,
        requestId: 'req_fallback',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 50,
    );
}

function fallbackMakeFakeScmDriver(): object
{
    return new class implements ScmDriverInterface
    {
        /** @var list<array{path: string, line: int, body: string}> */
        public array $inlineComments = [];

        /** @var list<string> */
        public array $summaryComments = [];

        private int $seq = 3000;

        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return new PullRequestDto(
                number: $prNumber,
                title: 'Fallback PR',
                state: 'OPEN',
                sourceBranch: 'feature/fallback',
                targetBranch: 'main',
                headSha: 'abc123',
                baseSha: 'base000',
                authorLogin: 'dev',
            );
        }

        public function getChangedFiles(string $scmRepoId, int $prNumber): Collection
        {
            return collect([
                new FileChangeDto(path: 'app/Foo.php', status: 'modified', linesAdded: 5, linesRemoved: 2),
            ]);
        }

        public function getDiff(string $scmRepoId, int $prNumber): ?string
        {
            return "diff --git a/app/Foo.php b/app/Foo.php\nindex 0000000..1111111 100644\n--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1,3 +1,4 @@\n <?php\n+class Foo {}";
        }

        public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
        {
            $this->inlineComments[] = ['path' => $payload->path, 'line' => $payload->line, 'body' => $payload->body];

            return new CommentHandle(scmCommentId: (string) $this->seq++);
        }

        public function postPullRequestComment(string $scmRepoId, int $prNumber, string $body): CommentHandle
        {
            $this->summaryComments[] = $body;

            return new CommentHandle(scmCommentId: (string) $this->seq++);
        }

        public function updateComment(string $scmRepoId, int $prNumber, CommentHandle $handle, string $body): CommentHandle
        {
            return $handle;
        }

        public function verifyCredentials(): CredentialCheck
        {
            return new CredentialCheck(valid: true, message: 'ok');
        }

        public function listRepositories(): Collection
        {
            return collect();
        }

        public function getRepository(string $scmRepoId): ?RepositoryDto
        {
            return null;
        }

        public function registerWebhook(string $scmRepoId, string $callbackUrl, string $secret): ?WebhookHandle
        {
            return null;
        }

        public function deleteWebhook(string $scmRepoId, ?WebhookHandle $handle): void {}
    };
}

function fallbackBindFakeScmFactory(object $fakeDriver): void
{
    app()->bind(ScmDriverFactory::class, fn () => new class($fakeDriver) extends ScmDriverFactory
    {
        public function __construct(private readonly object $driver) {}

        public function make(Workspace $workspace): ScmDriverInterface
        {
            return $this->driver;
        }
    });
}

function fallbackBindFakeTelemetry(): void
{
    app()->bind(LlmCallTelemetry::class, fn () => new class
    {
        public function record(mixed ...$args): mixed
        {
            return null;
        }

        public function recordDraft(mixed ...$args): mixed
        {
            return null;
        }

        public function recordCritique(mixed ...$args): mixed
        {
            return null;
        }
    });
}

// ---------------------------------------------------------------------------
// Shared teardown
// ---------------------------------------------------------------------------

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// AC47: critique failure → fail-open: unfiltered draft posted, job does not throw
// ---------------------------------------------------------------------------

it('AC47: critique throws RuntimeException — unfiltered draft is posted (all 5 findings), job does not rethrow', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $findings = array_map(fn (int $i) => fallbackMakeFinding($i), range(0, 4));
    $draft = fallbackMakeDraft($findings);

    $fakeDriver = fallbackMakeFakeScmDriver();

    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => $draft,
        critiqueDraft: fn () => throw new RuntimeException('boom'),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    fallbackBindFakeScmFactory($fakeDriver);
    fallbackBindFakeTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    // The job must NOT throw — fail-open behavior
    expect(function () use ($workspace, $repository): void {
        $job = new ReviewPullRequestJob(
            workspaceId: $workspace->id,
            repositoryId: $repository->id,
            pullRequestNumber: 42,
            headSha: 'abc123',
        );
        app()->call([$job, 'handle']);
    })->not->toThrow(Throwable::class);

    // All 5 unfiltered findings were posted inline (fail-open)
    expect($fakeDriver->inlineComments)->toHaveCount(5);

    // Review was marked Posted (not Failed — critique failure is non-fatal)
    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Posted);

    // error_class breadcrumb is set to 'critique_failed' on the review row
    expect($review->error_class)->toBe('critique_failed');
});

it('AC47: critique throws LlmReviewException — unfiltered draft still posted, job does not rethrow', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $findings = array_map(fn (int $i) => fallbackMakeFinding($i), range(0, 2));
    $draft = fallbackMakeDraft($findings);

    $fakeDriver = fallbackMakeFakeScmDriver();

    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => $draft,
        critiqueDraft: fn () => throw new LlmReviewException(
            message: 'critique rate limited',
            retryDecision: RetryDecision::Terminal,
        ),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    fallbackBindFakeScmFactory($fakeDriver);
    fallbackBindFakeTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    expect(function () use ($workspace, $repository): void {
        $job = new ReviewPullRequestJob(
            workspaceId: $workspace->id,
            repositoryId: $repository->id,
            pullRequestNumber: 42,
            headSha: 'abc123',
        );
        app()->call([$job, 'handle']);
    })->not->toThrow(Throwable::class);

    // All 3 findings posted
    expect($fakeDriver->inlineComments)->toHaveCount(3);

    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Posted);
    expect($review->error_class)->toBe('critique_failed');
});
