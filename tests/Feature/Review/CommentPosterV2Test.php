<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;
use App\Services\Review\CommentPoster;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use Illuminate\Support\Collection;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal fake SCM driver that records posted comments.
 */
function makeFakeScmDriverForPoster(): object
{
    return new class implements ScmDriverInterface
    {
        /** @var list<array{path: string, line: int, body: string}> */
        public array $inlineComments = [];

        /** @var list<string> */
        public array $summaryComments = [];

        private int $seq = 2000;

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

        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return null;
        }

        public function getChangedFiles(string $scmRepoId, int $prNumber): Collection
        {
            return collect();
        }

        public function getDiff(string $scmRepoId, int $prNumber): ?string
        {
            return null;
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

        public function postCommitStatus(string $scmRepoId, string $headSha, string $state, string $context, string $description, ?string $targetUrl = null): void {}
    };
}

/**
 * Build a DraftReviewDto with N findings, M nitpicks, and a walkthrough.
 *
 * @param  list<ReviewFindingDto>  $findings
 * @param  list<ReviewNitpickDto>  $nitpicks
 */
function buildDraft(array $findings, array $nitpicks, string $walkthrough = 'A detailed walkthrough.'): DraftReviewDto
{
    return new DraftReviewDto(
        summary: new ReviewSummaryV2Dto(
            overview: 'Review done.',
            riskLevel: 'medium',
            walkthrough: $walkthrough,
            filesAnalyzed: [],
        ),
        findings: $findings,
        nitpicks: $nitpicks,
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 20,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 10,
    );
}

/**
 * Build N ReviewFindingDtos.
 *
 * @return list<ReviewFindingDto>
 */
function buildFindings(int $count): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = new ReviewFindingDto(
            path: "app/File{$i}.php",
            line: $i + 1,
            severity: 'medium',
            category: 'correctness',
            message: "Finding {$i}.",
            suggestion: null,
        );
    }

    return $out;
}

/**
 * Build N ReviewNitpickDtos.
 *
 * @return list<ReviewNitpickDto>
 */
function buildNitpicks(int $count): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = new ReviewNitpickDto(
            path: "app/Nit{$i}.php",
            line: $i + 1,
            message: "Nitpick {$i}.",
        );
    }

    return $out;
}

/**
 * Create a persisted Review for a given workspace (required for DB writes in CommentPoster).
 */
function makeReviewForWorkspace(Workspace $workspace): Review
{
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    return Review::factory()->create([
        'workspace_id' => $workspace->id,
        'repository_id' => $repository->id,
        'pull_request_number' => 99,
        'head_sha' => 'headsha001',
    ]);
}

// ---------------------------------------------------------------------------
// AC48: CommentPoster::postV2 rendering
// ---------------------------------------------------------------------------

it('AC48: summary contains ## Walkthrough, walkthrough text, findings header, and nitpick details block', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $walkthrough = 'Detailed walkthrough of the changes in this PR.';
    $draft = buildDraft(buildFindings(3), buildNitpicks(4), $walkthrough);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    expect($scmDriver->summaryComments)->toHaveCount(1);
    $body = $scmDriver->summaryComments[0];

    expect($body)->toContain('## Walkthrough');
    expect($body)->toContain($walkthrough);
    expect($body)->toContain('## Findings');
    expect($body)->toContain('<details>');
    expect($body)->toContain('Nitpick 0.');
    expect($body)->toContain('Nitpick 1.');
    expect($body)->toContain('Nitpick 2.');
    expect($body)->toContain('Nitpick 3.');
});

it('AC48: nitpicks are never posted as inline comments — only in summary', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $draft = buildDraft(buildFindings(2), buildNitpicks(4));

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    // Only 2 inline comments (the findings), not 6
    expect($scmDriver->inlineComments)->toHaveCount(2);

    // None of the inline comment bodies mention "Nitpick"
    foreach ($scmDriver->inlineComments as $comment) {
        expect($comment['body'])->not->toContain('Nitpick');
    }

    // Summary has 1 comment containing the 4 nitpicks
    expect($scmDriver->summaryComments)->toHaveCount(1);
    expect($scmDriver->summaryComments[0])->toContain('Nitpick 0.');
    expect($scmDriver->summaryComments[0])->toContain('Nitpick 3.');
});

it('AC48: 25-finding cap — only 25 posted inline when 30 findings supplied', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $draft = buildDraft(buildFindings(30), []);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    // Exactly 25 inline comments (cap enforced)
    expect($scmDriver->inlineComments)->toHaveCount(25);

    // Summary mentions overflow
    expect($scmDriver->summaryComments)->toHaveCount(1);
    expect($scmDriver->summaryComments[0])->toContain('+5 more');
});

it('AC48: every comment body carries the AI-label prefix', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $draft = buildDraft(buildFindings(3), buildNitpicks(2));

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    $aiMarker = '🤖 cdv-rabbit (AI generated):';

    foreach ($scmDriver->inlineComments as $comment) {
        expect($comment['body'])->toContain($aiMarker);
    }

    foreach ($scmDriver->summaryComments as $body) {
        expect($body)->toContain($aiMarker);
    }
});

// ---------------------------------------------------------------------------
// AC52, AC55, AC56: Agent Prompt rendering (ADR 0006)
// ---------------------------------------------------------------------------

it('AC52: inline comment renders <details>🤖 Prompt for AI Agents</summary> block when agent_prompt is non-null', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $finding = new ReviewFindingDto(
        path: 'app/Services/Foo.php',
        line: 42,
        severity: 'high',
        category: 'bug',
        message: 'Null pointer on line 42.',
        suggestion: null,
        agentPrompt: 'In `@app/Services/Foo.php` around lines 40 - 44, add a null check before dereference.',
    );

    $draft = buildDraft([$finding], []);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    expect($scmDriver->inlineComments)->toHaveCount(1);
    $body = $scmDriver->inlineComments[0]['body'];

    expect($body)
        ->toContain('<details>')
        ->toContain('<summary>🤖 Prompt for AI Agents</summary>')
        ->toContain('Verify each finding against the current code and only fix it if needed.')
        ->toContain('@app/Services/Foo.php')
        ->toContain('around lines 40 - 44')
        ->toContain('</details>');
});

it('AC56: inline comment renders NO <details>🤖 block when agent_prompt is null', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $finding = new ReviewFindingDto(
        path: 'app/Services/Bar.php',
        line: 7,
        severity: 'low',
        category: 'maintainability',
        message: 'Trivial naming issue.',
        suggestion: 'Rename to $userCount.',
        agentPrompt: null,
    );

    $draft = buildDraft([$finding], []);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    expect($scmDriver->inlineComments)->toHaveCount(1);
    $body = $scmDriver->inlineComments[0]['body'];

    expect($body)
        ->not->toContain('Prompt for AI Agents')
        ->not->toContain('Verify each finding')
        ->toContain('Trivial naming issue.')
        ->toContain('**Suggested fix:**');
});

it('AC56: inline comment renders NO <details>🤖 block when agent_prompt is whitespace-only', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);
    $scmDriver = makeFakeScmDriverForPoster();

    $finding = new ReviewFindingDto(
        path: 'app/Services/Baz.php',
        line: 9,
        severity: 'low',
        category: 'bug',
        message: 'Edge case.',
        suggestion: null,
        agentPrompt: "  \n  \n  ",
    );

    $draft = buildDraft([$finding], []);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    expect($scmDriver->inlineComments[0]['body'])
        ->not->toContain('Prompt for AI Agents');
});

it('AC55: unresolved Findings (SCM 422 fallback) carry agent_prompt as nested <details> inside the summary', function (): void {
    $workspace = Workspace::factory()->create();
    $review = makeReviewForWorkspace($workspace);

    // Fake driver that throws on inline post — forces every Finding into the
    // unresolved bucket and out into postV2Summary.
    $scmDriver = new class extends stdClass implements ScmDriverInterface
    {
        public array $summaryComments = [];

        private int $seq = 3000;

        public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
        {
            throw new RuntimeException('422 path could not be resolved');
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

        public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
        {
            return null;
        }

        public function getChangedFiles(string $scmRepoId, int $prNumber): Collection
        {
            return collect();
        }

        public function getDiff(string $scmRepoId, int $prNumber): ?string
        {
            return null;
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

        public function postCommitStatus(string $scmRepoId, string $headSha, string $state, string $context, string $description, ?string $targetUrl = null): void {}
    };

    $finding = new ReviewFindingDto(
        path: 'app/Services/Foo.php',
        line: 42,
        severity: 'high',
        category: 'bug',
        message: 'Null pointer.',
        suggestion: null,
        agentPrompt: 'In `@app/Services/Foo.php` around lines 40 - 44, add a null check.',
    );

    $draft = buildDraft([$finding], []);

    app(CommentPoster::class)->postV2($review, $draft, 'repo-123', $scmDriver);

    expect($scmDriver->summaryComments)->toHaveCount(1);
    $body = $scmDriver->summaryComments[0];

    expect($body)
        ->toContain('Unresolved Findings (1)')
        ->toContain('Prompt for AI Agents')
        ->toContain('@app/Services/Foo.php')
        ->toContain('add a null check');
});

// ---------------------------------------------------------------------------
// AC54: agent_prompt survives the critic strip+rehydrate by construction
// ---------------------------------------------------------------------------

it('AC54: DraftReviewDto::toCriticInputArray() does NOT leak agent_prompt to the critic', function (): void {
    $finding = new ReviewFindingDto(
        path: 'app/Services/Foo.php',
        line: 42,
        severity: 'high',
        category: 'bug',
        message: 'Null pointer.',
        suggestion: null,
        agentPrompt: 'In `@app/Services/Foo.php` around lines 40 - 44, add a null check.',
    );

    $draft = buildDraft([$finding], []);
    $criticInput = $draft->toCriticInputArray();

    expect($criticInput['findings'])->toHaveCount(1);
    expect($criticInput['findings'][0])
        ->toHaveKey('path')
        ->toHaveKey('line')
        ->toHaveKey('severity')
        ->toHaveKey('category')
        ->toHaveKey('message')
        ->toHaveKey('suggestion')
        ->not->toHaveKey('agent_prompt');
});

it('AC54: withFindingsAtIndices() preserves the original agent_prompt on approved Findings', function (): void {
    $findingA = new ReviewFindingDto(
        path: 'app/A.php',
        line: 1,
        severity: 'high',
        category: 'bug',
        message: 'A bug.',
        suggestion: null,
        agentPrompt: 'In `@app/A.php` around lines 1 - 3, fix the bug.',
    );
    $findingB = new ReviewFindingDto(
        path: 'app/B.php',
        line: 2,
        severity: 'low',
        category: 'maintainability',
        message: 'B issue.',
        suggestion: null,
        agentPrompt: null,
    );

    $draft = buildDraft([$findingA, $findingB], []);
    $filtered = $draft->withFindingsAtIndices([0]);

    expect($filtered->findings)->toHaveCount(1);
    expect($filtered->findings[0]->agentPrompt)
        ->toBe('In `@app/A.php` around lines 1 - 3, fix the bug.');
});
