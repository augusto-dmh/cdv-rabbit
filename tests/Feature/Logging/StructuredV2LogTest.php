<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
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
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Fakes\FakeCostReservation;
use Tests\Fakes\FakeLlmDriver;

// ---------------------------------------------------------------------------
// W7-T5 telemetry contract: the v2 pipeline emits a `review.v2_pipeline` log
// line carrying schema_version, critique_findings_approved, critique_findings_rejected,
// and nitpick_count so dashboards can compare v1 vs v2 quality without DB joins.
// v1 reviews must NOT emit those v2-specific fields.
// ---------------------------------------------------------------------------

function v2LogFakeScm(): object
{
    return new class implements ScmDriverInterface
    {
        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return new PullRequestDto(
                number: $prNumber,
                title: 'log test',
                state: 'OPEN',
                sourceBranch: 'feat/x',
                targetBranch: 'main',
                headSha: 'sha-log',
                baseSha: 'base-log',
                authorLogin: 'tester',
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

function v2LogBindScm(object $driver): void
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

function v2LogBindNoopTelemetry(): void
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

function v2LogAttachTestHandler(): TestHandler
{
    $handler = new TestHandler(Level::Info);
    Log::channel('cdv-rabbit-reviews')->getLogger()->pushHandler($handler);

    return $handler;
}

function v2LogDraftWith(int $findings, int $nitpicks): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Reviewed.',
            riskLevel: 'medium',
            walkthrough: 'A walkthrough.',
            filesAnalyzed: [],
        ),
        findings: array_map(fn (int $i) => new ReviewFindingDto(
            path: "app/F{$i}.php",
            line: $i + 1,
            severity: 'medium',
            category: 'correctness',
            message: "msg {$i}",
            suggestion: null,
        ), range(0, $findings - 1)),
        nitpicks: array_map(fn (int $i) => new ReviewNitpickDto(
            path: "app/F{$i}.php",
            line: $i + 1,
            message: "nit {$i}",
        ), range(0, $nitpicks - 1)),
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

function v2LogCritique(array $approvedIndices, array $rejectedIndices): CritiqueResultDto
{
    $decisions = [];
    foreach ($approvedIndices as $i) {
        $decisions[] = ['finding_index' => $i, 'verdict' => 'approve', 'reason' => ''];
    }
    foreach ($rejectedIndices as $i) {
        $decisions[] = ['finding_index' => $i, 'verdict' => 'reject', 'reason' => 'no'];
    }

    return new CritiqueResultDto(
        decisions: $decisions,
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

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

it('W7-T5: v2 pipeline emits review.v2_pipeline log carrying schema_version, approved/rejected counts and nitpick_count', function (): void {
    $workspace = Workspace::factory()->v2()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $handler = v2LogAttachTestHandler();

    $fakeLlm = new FakeLlmDriver(
        reviewDiffV2: fn () => v2LogDraftWith(findings: 4, nitpicks: 2),
        critiqueDraft: fn () => v2LogCritique([0, 1, 2], [3]),
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    v2LogBindScm(v2LogFakeScm());
    v2LogBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 7,
        headSha: 'sha-v2-log',
    );
    app()->call([$job, 'handle']);

    $records = collect($handler->getRecords());
    $v2Log = $records->first(fn (LogRecord $r) => $r->message === 'review.v2_pipeline');

    expect($v2Log)->not->toBeNull();
    expect($v2Log->context)->toHaveKey('schema_version', 'v2');
    expect($v2Log->context)->toHaveKey('critique_findings_approved', 3);
    expect($v2Log->context)->toHaveKey('critique_findings_rejected', 1);
    expect($v2Log->context)->toHaveKey('nitpick_count', 2);
});

it('W7-T5: v1 pipeline does NOT emit the review.v2_pipeline log line', function (): void {
    $workspace = Workspace::factory()->create(); // default v1
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $handler = v2LogAttachTestHandler();

    $v1Result = new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'ok', riskLevel: 'low'),
        comments: [new ReviewCommentDto(path: 'app/Foo.php', line: 1, severity: 'low', message: 'x')],
        inputTokens: 50,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 20,
        requestId: 'req-v1',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 5,
    );

    $fakeLlm = new FakeLlmDriver(
        reviewDiff: fn () => $v1Result,
    );

    app()->bind(LlmDriverInterface::class, fn () => $fakeLlm);
    bindFakeLlmFactory();
    v2LogBindScm(v2LogFakeScm());
    v2LogBindNoopTelemetry();
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 8,
        headSha: 'sha-v1-log',
    );
    app()->call([$job, 'handle']);

    $records = collect($handler->getRecords());
    $v2Log = $records->first(fn (LogRecord $r) => $r->message === 'review.v2_pipeline');

    expect($v2Log)->toBeNull();
});
