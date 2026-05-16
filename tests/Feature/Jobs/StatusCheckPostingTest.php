<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
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
use Illuminate\Support\Collection;
use Tests\Fakes\FakeCostReservation;
use Tests\Fakes\FakeLlmDriver;

// ---------------------------------------------------------------------------
// AC51: every reviewed PR receives a `pending` commit status at job start and
// a `success` or `failure` status at job end, persisted on reviews.status_check_state
// so consumer repos can gate auto-merge on `cdv-rabbit/review`.
// ---------------------------------------------------------------------------

/**
 * Fake SCM driver that records every postCommitStatus invocation while behaving
 * normally for the rest of the SCM surface a v2 review touches.
 */
function statusCheckFakeScm(): object
{
    return new class implements ScmDriverInterface
    {
        /** @var list<array{state: string, sha: string, context: string, description: string}> */
        public array $commitStatuses = [];

        public ?string $reportedHeadSha = null;

        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return new PullRequestDto(
                number: $prNumber,
                title: 'Status check test',
                state: 'OPEN',
                sourceBranch: 'feat/x',
                targetBranch: 'main',
                headSha: $this->reportedHeadSha ?? '',
                baseSha: 'base000',
                authorLogin: 'dev',
            );
        }

        public function getChangedFiles(string $scmRepoId, int $prNumber): Collection
        {
            return collect([
                new FileChangeDto(path: 'app/Foo.php', status: 'modified', linesAdded: 10, linesRemoved: 5),
            ]);
        }

        public function getDiff(string $scmRepoId, int $prNumber): ?string
        {
            return "diff --git a/app/Foo.php b/app/Foo.php\n--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1,3 +1,4 @@\n <?php\n+class Foo {}";
        }

        public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
        {
            return new CommentHandle(scmCommentId: '1');
        }

        public function postPullRequestComment(string $scmRepoId, int $prNumber, string $body): CommentHandle
        {
            return new CommentHandle(scmCommentId: '2');
        }

        public function updateComment(string $scmRepoId, int $prNumber, CommentHandle $handle, string $body): CommentHandle
        {
            return $handle;
        }

        public function verifyCredentials(): CredentialCheck
        {
            return new CredentialCheck(valid: true, identity: 'fake');
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

        public function postCommitStatus(string $scmRepoId, string $headSha, string $state, string $context, string $description, ?string $targetUrl = null): void
        {
            $this->commitStatuses[] = [
                'state' => $state,
                'sha' => $headSha,
                'context' => $context,
                'description' => $description,
            ];
        }
    };
}

function statusCheckBindScm(object $driver): void
{
    app()->bind(ScmDriverFactory::class, fn () => new class($driver) extends ScmDriverFactory
    {
        public function __construct(private readonly object $driver) {}

        public function make(Workspace $workspace): ScmDriverInterface
        {
            return $this->driver;
        }
    });
}

function statusCheckBindNoopTelemetry(): void
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

function statusCheckDraft(string $severity): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Reviewed.',
            riskLevel: $severity === 'high' ? 'high' : 'medium',
            walkthrough: 'Walkthrough.',
            filesAnalyzed: [],
        ),
        findings: [
            new ReviewFindingDto(
                path: 'app/Foo.php',
                line: 1,
                severity: $severity,
                category: 'correctness',
                message: 'Issue.',
                suggestion: null,
            ),
        ],
        nitpicks: [],
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 50,
        requestId: 'req-draft',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

function statusCheckApproveAll(): CritiqueResultDto
{
    return new CritiqueResultDto(
        decisions: [['finding_index' => 0, 'verdict' => 'approve', 'reason' => '']],
        inputTokens: 50,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 20,
        requestId: 'req-critique',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

function statusCheckDispatch(Workspace $workspace, Repository $repository, string $sha, ?object $fakeScm = null): void
{
    if ($fakeScm !== null) {
        $fakeScm->reportedHeadSha = $sha;
    }

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 99,
        headSha: $sha,
    );
    app()->call([$job, 'handle']);
}

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

it('AC51: posts a pending status on the PR head SHA at job start', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $fakeScm = statusCheckFakeScm();
    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => statusCheckDraft('low'),
        critiqueDraft: fn () => statusCheckApproveAll(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    statusCheckBindScm($fakeScm);
    statusCheckBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    statusCheckDispatch($workspace, $repository, 'sha-start', $fakeScm);

    expect($fakeScm->commitStatuses[0]['state'])->toBe('pending');
    expect($fakeScm->commitStatuses[0]['sha'])->toBe('sha-start');
    expect($fakeScm->commitStatuses[0]['context'])->toBe(config('cdv-rabbit.status_check_context'));
});

it('AC51: posts success when no high-severity findings and persists status_check_state=success', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $fakeScm = statusCheckFakeScm();
    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => statusCheckDraft('medium'),
        critiqueDraft: fn () => statusCheckApproveAll(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    statusCheckBindScm($fakeScm);
    statusCheckBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    statusCheckDispatch($workspace, $repository, 'sha-success', $fakeScm);

    $states = array_column($fakeScm->commitStatuses, 'state');
    expect($states)->toContain('pending')
        ->and($states)->toContain('success')
        ->and(end($states))->toBe('success');

    $review = Review::where('workspace_id', $workspace->id)->where('head_sha', 'sha-success')->firstOrFail();
    expect($review->status_check_state)->toBe('success');
});

it('AC51: posts failure when at least one high-severity finding and persists status_check_state=failure', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $fakeScm = statusCheckFakeScm();
    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => statusCheckDraft('high'),
        critiqueDraft: fn () => statusCheckApproveAll(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    statusCheckBindScm($fakeScm);
    statusCheckBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    statusCheckDispatch($workspace, $repository, 'sha-fail', $fakeScm);

    $states = array_column($fakeScm->commitStatuses, 'state');
    expect($states)->toContain('pending')
        ->and(end($states))->toBe('failure');

    $review = Review::where('workspace_id', $workspace->id)->where('head_sha', 'sha-fail')->firstOrFail();
    expect($review->status_check_state)->toBe('failure');
});

it('AC51: uses the cdv-rabbit.status_check_context configured value as the status context', function (): void {
    config(['cdv-rabbit.status_check_context' => 'cdv-rabbit/custom']);

    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $fakeScm = statusCheckFakeScm();
    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => statusCheckDraft('low'),
        critiqueDraft: fn () => statusCheckApproveAll(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    statusCheckBindScm($fakeScm);
    statusCheckBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    statusCheckDispatch($workspace, $repository, 'sha-context', $fakeScm);

    foreach ($fakeScm->commitStatuses as $entry) {
        expect($entry['context'])->toBe('cdv-rabbit/custom');
    }
});
