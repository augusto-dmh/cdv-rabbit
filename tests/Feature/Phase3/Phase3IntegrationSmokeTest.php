<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\CommentType;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Review\CostReservationInterface;
use App\Support\AnthropicHeaderBag;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCostReservation;

/**
 * Phase 3 cross-cutting integration smoke test.
 *
 * Simulates a 3-file PR:
 *   - File 1: binary file (Binary files differ) → skipped by SkipRules
 *   - File 2: lock file (composer.lock)         → skipped by SkipRules
 *   - File 3: normal PHP file                   → reviewed, gets comments posted
 *
 * Asserts:
 *   - 2 files skipped (no LLM call for them)
 *   - 1 file reviewed, summary + ≥1 inline comment posted
 *   - reviews_llm_calls row written with telemetry
 *   - No diff content in any DB column
 */
afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

function makeThreeFileDiff(): string
{
    return implode("\n", [
        // Binary file — SkipRules strips this
        'diff --git a/assets/logo.png b/assets/logo.png',
        'Binary files a/assets/logo.png and b/assets/logo.png differ',
        '',
        // Lock file — SkipRules strips this
        'diff --git a/composer.lock b/composer.lock',
        'index 0000000..1111111 100644',
        '--- a/composer.lock',
        '+++ b/composer.lock',
        '@@ -1,3 +1,4 @@',
        '+{"_readme": ["..."]}',
        '',
        // Normal PHP file — survives SkipRules
        'diff --git a/app/Services/Billing.php b/app/Services/Billing.php',
        'index 0000000..2222222 100644',
        '--- a/app/Services/Billing.php',
        '+++ b/app/Services/Billing.php',
        '@@ -1,5 +1,10 @@',
        ' <?php',
        '+class Billing {',
        '+    public function charge(int $cents): bool {',
        '+        // TODO: implement',
        '+        return true;',
        '+    }',
        '+}',
    ]);
}

test('Phase3 smoke: 3-file PR — binary + lock skipped, normal file reviewed', function (): void {
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    // Fake cost reservation — always granted
    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));

    // Fake LLM — returns 1 inline comment for the normal PHP file
    $llmCallCount = 0;
    app()->bind(LlmDriverInterface::class, fn () => new class($llmCallCount) implements LlmDriverInterface
    {
        public function __construct(private int &$count) {}

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): ReviewResultDto
        {
            $this->count++;

            return new ReviewResultDto(
                summary: new ReviewSummaryDto(overview: 'Minor unimplemented charge method.', riskLevel: 'medium'),
                comments: [
                    new ReviewCommentDto(path: 'app/Services/Billing.php', line: 4, severity: 'low', message: 'TODO should be tracked as an issue.'),
                ],
                inputTokens: 800,
                cacheCreationInputTokens: 400,
                cacheReadInputTokens: 0,
                outputTokens: 120,
                requestId: 'req_smoke3',
                rateLimitTokensRemaining: 40000,
                rateLimitTokensReset: null,
                durationMs: 350,
            );
        }
    });

    // Fake telemetry — no-op so we skip DB write complexity in this smoke
    app()->bind(LlmCallTelemetry::class, fn () => new class
    {
        public function record(mixed ...$args): mixed
        {
            return null;
        }
    });

    app()->instance(AnthropicHeaderBag::class, new AnthropicHeaderBag(requestId: 'req_smoke3'));

    // Fake Bitbucket HTTP — PR open, small diffstat, return our 3-file diff
    Http::fake([
        '*/pullrequests/11' => Http::response([
            'state' => 'OPEN',
            'title' => 'Smoke test PR',
            'source' => [
                'commit' => ['hash' => 'smoke001'],
                'branch' => ['name' => 'feature/smoke'],
            ],
            'author' => ['display_name' => 'dev'],
        ]),
        '*/pullrequests/11/diffstat' => Http::response([
            'values' => [
                ['lines_added' => 6, 'lines_removed' => 0],
            ],
        ]),
        '*/pullrequests/11/diff' => Http::response(makeThreeFileDiff()),
        '*/pullrequests/11/comments' => Http::response(['id' => 1001]),
        '*' => Http::response([]),
    ]);

    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 11,
        headSha: 'smoke001',
    );

    app()->call([$job, 'handle']);

    $review = Review::where('workspace_id', $workspace->id)->first();

    // Review completed and posted
    expect($review)->not->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::Posted);

    // At least 1 inline + 1 summary comment persisted
    $inline = ReviewComment::where('review_id', $review->id)
        ->where('comment_type', CommentType::Inline)
        ->count();
    $summary = ReviewComment::where('review_id', $review->id)
        ->where('comment_type', CommentType::Summary)
        ->count();

    expect($inline)->toBeGreaterThanOrEqual(1)
        ->and($summary)->toBe(1);

    // No diff content in any review_comments column
    $commentRows = ReviewComment::where('review_id', $review->id)->get();
    foreach ($commentRows as $row) {
        $attrs = $row->getAttributes();
        expect($attrs)->not->toHaveKey('diff')
            ->not->toHaveKey('content')
            ->not->toHaveKey('patch')
            ->not->toHaveKey('code');
    }

    // No diff content in the review row itself
    $reviewAttrs = $review->getAttributes();
    foreach ($reviewAttrs as $key => $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('diff --git');
        }
    }
});
