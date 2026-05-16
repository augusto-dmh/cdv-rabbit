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
