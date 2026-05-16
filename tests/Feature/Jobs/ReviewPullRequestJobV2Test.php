<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\LlmCallRole;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
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
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a DraftReviewDto with the given number of Findings and Nitpicks.
 *
 * @param  list<ReviewFindingDto>  $findings
 * @param  list<ReviewNitpickDto>  $nitpicks
 */
function makeDraftReviewDto(array $findings, array $nitpicks, string $walkthrough = 'This PR adds a new Foo class.'): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Review complete.',
            riskLevel: 'medium',
            walkthrough: $walkthrough,
            filesAnalyzed: [],
        ),
        findings: $findings,
        nitpicks: $nitpicks,
        inputTokens: 500,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 100,
        requestId: 'req_draft',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 50,
    );
}

/**
 * Build a ReviewFindingDto at the given index (path/line derived from index).
 */
function makeReviewFinding(int $index, string $severity = 'medium'): ReviewFindingDto
{
    return new ReviewFindingDto(
        path: "app/File{$index}.php",
        line: $index + 1,
        severity: $severity,
        category: 'correctness',
        message: "Finding {$index} message.",
        suggestion: null,
    );
}

/**
 * Build a ReviewNitpickDto.
 */
function makeNitpick(int $index): ReviewNitpickDto
{
    return new ReviewNitpickDto(
        path: "app/File{$index}.php",
        line: $index + 1,
        message: "Nitpick {$index} message.",
    );
}

/**
 * Build a CritiqueResultDto approving the given indices and rejecting others.
 *
 * @param  list<int>  $approvedIndices
 * @param  list<int>  $rejectedIndices
 */
function makeCritiqueResultDto(array $approvedIndices, array $rejectedIndices): CritiqueResultDto
{
    $decisions = [];
    foreach ($approvedIndices as $idx) {
        $decisions[] = ['finding_index' => $idx, 'verdict' => 'approve', 'reason' => ''];
    }
    foreach ($rejectedIndices as $idx) {
        $decisions[] = ['finding_index' => $idx, 'verdict' => 'reject', 'reason' => "Rejected reason for {$idx}."];
    }

    return new CritiqueResultDto(
        decisions: $decisions,
        inputTokens: 300,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 80,
        requestId: 'req_critique',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 30,
    );
}

/**
 * Create a fake SCM driver that records all postInlineComment and postPullRequestComment calls.
 */
function makeFakeScmDriver(): object
{
    return new class implements ScmDriverInterface
    {
        /** @var list<array{path: string, line: int}> */
        public array $inlineComments = [];

        /** @var list<string> */
        public array $summaryComments = [];

        private int $commentIdSeq = 1000;

        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return new PullRequestDto(
                number: $prNumber,
                title: 'Add feature X',
                state: 'OPEN',
                sourceBranch: 'feature/x',
                targetBranch: 'main',
                headSha: 'abc123',
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
            return "diff --git a/app/Foo.php b/app/Foo.php\nindex 0000000..1111111 100644\n--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1,3 +1,4 @@\n <?php\n+class Foo {}";
        }

        public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
        {
            $this->inlineComments[] = ['path' => $payload->path, 'line' => $payload->line, 'body' => $payload->body];

            return new CommentHandle(scmCommentId: (string) $this->commentIdSeq++);
        }

        public function postPullRequestComment(string $scmRepoId, int $prNumber, string $body): CommentHandle
        {
            $this->summaryComments[] = $body;

            return new CommentHandle(scmCommentId: (string) $this->commentIdSeq++);
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

        /** @var list<array{state: string, sha: string, context: string, description: string}> */
        public array $commitStatuses = [];

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

/**
 * Bind a fake ScmDriverFactory that returns the given driver instance.
 * Uses app()->bind() so the container always returns our subclass on resolution.
 */
function bindFakeScmFactory(object $fakeDriver): void
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

/**
 * Bind a full no-op LlmCallTelemetry so tests that don't need real DB rows
 * don't hit the SQLite enum CHECK constraint on the role column.
 */
function bindFakeV2Telemetry(): void
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

/**
 * Dispatch the v2 job synchronously.
 */
function dispatchV2Job(Workspace $workspace, Repository $repository, string $sha = 'abc123'): void
{
    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 42,
        headSha: $sha,
    );

    app()->call([$job, 'handle']);
}

// ---------------------------------------------------------------------------
// Shared teardown
// ---------------------------------------------------------------------------

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// AC46: v2 happy-path end-to-end
// ---------------------------------------------------------------------------

it('AC46: v2 happy path — 3 approved findings posted inline, 2 nitpicks in summary, walkthrough in summary', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    // 5 findings, 2 nitpicks
    $findings = array_map(fn (int $i) => makeReviewFinding($i), range(0, 4));
    $nitpicks = [makeNitpick(10), makeNitpick(11)];
    $walkthrough = 'This PR adds a new Foo class with constructor injection.';
    $draft = makeDraftReviewDto($findings, $nitpicks, $walkthrough);

    // Critique approves indices 0,1,2 — rejects 3,4
    $critique = makeCritiqueResultDto([0, 1, 2], [3, 4]);

    $fakeDriver = makeFakeScmDriver();

    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => $draft,
        critiqueDraft: fn () => $critique,
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    bindFakeScmFactory($fakeDriver);
    bindFakeV2Telemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    dispatchV2Job($workspace, $repository);

    // Assert review posted
    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Posted);

    // Exactly 3 inline comments (approved findings 0, 1, 2)
    expect($fakeDriver->inlineComments)->toHaveCount(3);

    // 1 summary comment
    expect($fakeDriver->summaryComments)->toHaveCount(1);
    $summaryBody = $fakeDriver->summaryComments[0];

    // Walkthrough appears in summary
    expect($summaryBody)->toContain('## Walkthrough');
    expect($summaryBody)->toContain($walkthrough);

    // Nitpicks appear in summary <details>
    expect($summaryBody)->toContain('<details>');
    expect($summaryBody)->toContain('Nitpick 10 message.');
    expect($summaryBody)->toContain('Nitpick 11 message.');

    // Findings header present
    expect($summaryBody)->toContain('## Findings');

    // Nitpicks NOT posted as inline comments
    $inlineBodies = array_column($fakeDriver->inlineComments, 'body');
    foreach ($inlineBodies as $body) {
        expect($body)->not->toContain('Nitpick');
    }
});

it('AC46: telemetry records draft and critique calls — no review role call', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $findings = [makeReviewFinding(0)];
    $draft = makeDraftReviewDto($findings, []);
    $critique = makeCritiqueResultDto([0], []);

    $fakeDriver = makeFakeScmDriver();
    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => $draft,
        critiqueDraft: fn () => $critique,
    );

    // Spy on telemetry using a shared stdClass bag so the closure captures by reference.
    $telemetrySpy = new stdClass;
    $telemetrySpy->roles = [];
    app()->bind(LlmCallTelemetry::class, function () use ($telemetrySpy) {
        return new class($telemetrySpy)
        {
            public function __construct(private readonly stdClass $spy) {}

            public function record(mixed ...$args): mixed
            {
                return null;
            }

            public function recordDraft(mixed ...$args): mixed
            {
                $this->spy->roles[] = LlmCallRole::Draft->value;

                return null;
            }

            public function recordCritique(mixed ...$args): mixed
            {
                $this->spy->roles[] = LlmCallRole::Critique->value;

                return null;
            }
        };
    });

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    bindFakeScmFactory($fakeDriver);
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    dispatchV2Job($workspace, $repository);

    expect($telemetrySpy->roles)->toContain(LlmCallRole::Draft->value);
    expect($telemetrySpy->roles)->toContain(LlmCallRole::Critique->value);
    expect($telemetrySpy->roles)->not->toContain(LlmCallRole::Review->value);
});
