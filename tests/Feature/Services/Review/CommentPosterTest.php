<?php

declare(strict_types=1);

use App\Enums\CommentType;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Review\CommentPoster;
use App\Services\Review\CommentSanitizer;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\InlineCommentPayload;

function makeReviewResult(int $commentCount = 0, string $riskLevel = 'medium'): ReviewResultDto
{
    $comments = [];
    for ($i = 1; $i <= $commentCount; $i++) {
        $comments[] = new ReviewCommentDto(
            path: "src/File{$i}.php",
            line: $i,
            severity: 'medium',
            message: "Issue {$i}",
        );
    }

    return new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Overall summary.', riskLevel: $riskLevel),
        comments: $comments,
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 50,
        requestId: 'req_test',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 200,
    );
}

function makeCommentPoster(): CommentPoster
{
    return new CommentPoster(sanitizer: new CommentSanitizer);
}

test('AC26: empty comments + risk_level=low posts only summary comment', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 1]);
    $dto = makeReviewResult(commentCount: 0, riskLevel: 'low');

    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(new CommentHandle(scmCommentId: '101'));
    $driver->shouldReceive('postInlineComment')->never();

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    $comments = ReviewComment::where('review_id', $review->id)->get();
    expect($comments)->toHaveCount(1)
        ->and($comments->first()->comment_type)->toBe(CommentType::Summary);
});

test('AC5: 30 comments are capped to 25 inline + 1 summary with overflow note', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 2]);
    $dto = makeReviewResult(commentCount: 30, riskLevel: 'high');

    $postedSummaries = [];
    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postInlineComment')
        ->times(25)
        ->andReturn(new CommentHandle(scmCommentId: '200'));
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturnUsing(function ($id, $pr, $text) use (&$postedSummaries) {
            $postedSummaries[] = $text;

            return new CommentHandle(scmCommentId: '201');
        });

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    $inline = ReviewComment::where('review_id', $review->id)
        ->where('comment_type', CommentType::Inline)
        ->count();
    $summaryCount = ReviewComment::where('review_id', $review->id)
        ->where('comment_type', CommentType::Summary)
        ->count();

    expect($inline)->toBe(25)
        ->and($summaryCount)->toBe(1)
        ->and($postedSummaries[0])->toContain('+5 more');
});

test('AC6: every inline comment body is prefixed with AI marker', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 3]);
    $dto = makeReviewResult(commentCount: 2, riskLevel: 'medium');

    $postedBodies = [];
    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postInlineComment')
        ->twice()
        ->andReturnUsing(function ($id, $pr, InlineCommentPayload $payload) use (&$postedBodies) {
            $postedBodies[] = $payload->body;

            return new CommentHandle(scmCommentId: '300');
        });
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(new CommentHandle(scmCommentId: '301'));

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    foreach ($postedBodies as $body) {
        expect($body)->toContain('🤖 cdv-rabbit (AI generated):');
    }
});

test('AC6: summary comment is also prefixed with AI marker', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 4]);
    $dto = makeReviewResult(commentCount: 0, riskLevel: 'low');

    $summaryText = null;
    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturnUsing(function ($id, $pr, $text) use (&$summaryText) {
            $summaryText = $text;

            return new CommentHandle(scmCommentId: '400');
        });

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    expect($summaryText)->toContain('🤖 cdv-rabbit (AI generated):');
});

test('AC7: existing review_comment row at same path+line triggers update not post', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 5]);

    ReviewComment::create([
        'review_id' => $review->id,
        'workspace_id' => $review->workspace_id,
        'file_path' => 'src/File1.php',
        'line' => 1,
        'bitbucket_comment_id' => '777',
        'posted_at' => now(),
        'comment_type' => CommentType::Inline,
    ]);

    $dto = makeReviewResult(commentCount: 1, riskLevel: 'medium');

    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('updateComment')
        ->once()
        ->withArgs(function ($scmRepoId, $prNumber, CommentHandle $handle, $body): bool {
            return $scmRepoId === 'org/repo'
                && $prNumber === 5
                && $handle->scmCommentId === '777';
        })
        ->andReturn(new CommentHandle(scmCommentId: '777'));
    $driver->shouldReceive('postInlineComment')->never();
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(new CommentHandle(scmCommentId: '500'));

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    $inline = ReviewComment::where('review_id', $review->id)
        ->where('comment_type', CommentType::Inline)
        ->count();
    expect($inline)->toBe(1);
});

test('sanitizer strips @mentions before posting', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 6]);

    $dto = new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Good PR.', riskLevel: 'low'),
        comments: [
            new ReviewCommentDto(
                path: 'src/Foo.php',
                line: 10,
                severity: 'medium',
                message: 'Please fix this @dev1 and check with @qa-team.',
            ),
        ],
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 50,
        requestId: 'req_sanitize',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 100,
    );

    $postedInline = null;
    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postInlineComment')
        ->once()
        ->andReturnUsing(function ($id, $pr, InlineCommentPayload $payload) use (&$postedInline) {
            $postedInline = $payload->body;

            return new CommentHandle(scmCommentId: '600');
        });
    $driver->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(new CommentHandle(scmCommentId: '601'));

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    expect($postedInline)->not->toContain('@dev1')
        ->not->toContain('@qa-team');
});

test('review_comments rows contain no diff or code content columns', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 7]);
    $dto = makeReviewResult(commentCount: 1, riskLevel: 'medium');

    $driver = Mockery::mock(ScmDriverInterface::class);
    $driver->shouldReceive('postInlineComment')->once()->andReturn(new CommentHandle(scmCommentId: '700'));
    $driver->shouldReceive('postPullRequestComment')->once()->andReturn(new CommentHandle(scmCommentId: '701'));

    makeCommentPoster()->post($review, $dto, 'org/repo', $driver);

    $columns = ReviewComment::where('review_id', $review->id)->get()->map->getAttributes()->toArray();

    foreach ($columns as $row) {
        expect($row)->not->toHaveKey('content')
            ->not->toHaveKey('diff')
            ->not->toHaveKey('patch')
            ->not->toHaveKey('code');
    }
});
