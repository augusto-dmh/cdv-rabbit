<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\LlmCallRole;
use App\Enums\ReviewSchemaVersion;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
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
// AC49: flipping review_schema_version from v2 back to v1 routes the next
// review through the v1 pipeline (one record() call with role=review). Proves
// rollback is a per-Workspace enum flip with no migration. Test asserts on
// the role(s) recorded via LlmCallTelemetry — counting rows would conflict
// with the SQLite enum CHECK constraint on reviews_llm_calls.role.
// ---------------------------------------------------------------------------

function rollbackFakeScm(): object
{
    return new class implements ScmDriverInterface
    {
        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return new PullRequestDto(
                number: $prNumber,
                title: 'Rollback test',
                state: 'OPEN',
                sourceBranch: 'feat/x',
                targetBranch: 'main',
                headSha: 'sha-rollback',
                baseSha: 'base-rollback',
                authorLogin: 'tester',
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

        public function postCommitStatus(string $scmRepoId, string $headSha, string $state, string $context, string $description, ?string $targetUrl = null): void {}
    };
}

function rollbackBindScm(object $driver): void
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

function rollbackBindTelemetrySpy(stdClass $spy): void
{
    $spy->roles = [];

    app()->bind(LlmCallTelemetry::class, fn () => new class($spy)
    {
        public function __construct(private readonly stdClass $spy) {}

        public function record(mixed ...$args): mixed
        {
            // record() is invoked with named arguments by ReviewPullRequestJob's v1 path;
            // accept either positional or named to keep this fake robust.
            $role = $args['role'] ?? $args[3] ?? null;
            if ($role instanceof LlmCallRole) {
                $this->spy->roles[] = $role->value;
            }

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
    });
}

function rollbackV1ReviewResult(): ReviewResultDto
{
    return new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Looks good.', riskLevel: 'low'),
        comments: [new ReviewCommentDto(path: 'app/Foo.php', line: 2, severity: 'low', message: 'Nit.')],
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 50,
        requestId: 'req-v1',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

function rollbackV2Draft(): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Reviewed.',
            riskLevel: 'medium',
            walkthrough: 'Adds a Foo class.',
            filesAnalyzed: [],
        ),
        findings: [
            new ReviewFindingDto(
                path: 'app/Foo.php',
                line: 1,
                severity: 'medium',
                category: 'correctness',
                message: 'Missing docblock.',
                suggestion: null,
            ),
        ],
        nitpicks: [],
        inputTokens: 300,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 80,
        requestId: 'req-v2-draft',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

function rollbackV2Critique(): CritiqueResultDto
{
    return new CritiqueResultDto(
        decisions: [['finding_index' => 0, 'verdict' => 'approve', 'reason' => '']],
        inputTokens: 200,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 40,
        requestId: 'req-v2-critique',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

it('AC49: a v2 review on a v2 workspace records draft + critique telemetry roles (no v1 review role)', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $spy = new stdClass;
    rollbackBindTelemetrySpy($spy);

    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => rollbackV2Draft(),
        critiqueDraft: fn () => rollbackV2Critique(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    rollbackBindScm(rollbackFakeScm());
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 1,
        headSha: 'sha-v2',
    );
    app()->call([$job, 'handle']);

    expect($spy->roles)->toContain(LlmCallRole::Draft->value)
        ->and($spy->roles)->toContain(LlmCallRole::Critique->value)
        ->and($spy->roles)->not->toContain(LlmCallRole::Review->value);
});

it('AC49: after flipping the workspace from v2 to v1, the next review uses the v1 path (single review-role telemetry call)', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    // Per-Workspace zero-migration rollback (plan §8): admin flips the enum back to v1.
    $workspace->update(['review_schema_version' => ReviewSchemaVersion::V1]);

    $spy = new stdClass;
    rollbackBindTelemetrySpy($spy);

    $fakeLlm = new FakeLlmDriver(
        reviewDiff: fn () => rollbackV1ReviewResult(),
        reviewDiffV2: fn () => rollbackV2Draft(),
        critiqueDraft: fn () => rollbackV2Critique(),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    rollbackBindScm(rollbackFakeScm());
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 2,
        headSha: 'sha-rollback',
    );
    app()->call([$job, 'handle']);

    expect($workspace->fresh()->review_schema_version)->toBe(ReviewSchemaVersion::V1)
        ->and($spy->roles)->toContain(LlmCallRole::Review->value)
        ->and($spy->roles)->not->toContain(LlmCallRole::Draft->value)
        ->and($spy->roles)->not->toContain(LlmCallRole::Critique->value);
});
