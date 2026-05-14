<?php

declare(strict_types=1);

use App\Enums\CommentType;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Review\CommentPoster;
use App\Services\Review\CommentSanitizer;

// Build a ReviewResultDto with N inline comments.
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

// Build a CommentPoster with a Mockery-based BitbucketClient mock.
function makeCommentPoster(?BitbucketClient $client = null): CommentPoster
{
    return new CommentPoster(
        bitbucket: $client ?? Mockery::mock(BitbucketClient::class),
        sanitizer: new CommentSanitizer,
    );
}

test('AC26: empty comments + risk_level=low posts only summary comment', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 1]);
    $dto = makeReviewResult(commentCount: 0, riskLevel: 'low');

    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(['id' => 101]);
    $client->shouldReceive('postInlineComment')->never();

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

    $comments = ReviewComment::where('review_id', $review->id)->get();
    expect($comments)->toHaveCount(1)
        ->and($comments->first()->comment_type)->toBe(CommentType::Summary);
});

test('AC5: 30 comments are capped to 25 inline + 1 summary with overflow note', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 2]);
    $dto = makeReviewResult(commentCount: 30, riskLevel: 'high');

    $postedSummaries = [];
    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postInlineComment')
        ->times(25)
        ->andReturn(['id' => 200]);
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturnUsing(function ($slug, $pr, $text) use (&$postedSummaries) {
            $postedSummaries[] = $text;

            return ['id' => 201];
        });

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

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
    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postInlineComment')
        ->twice()
        ->andReturnUsing(function ($slug, $pr, $text) use (&$postedBodies) {
            $postedBodies[] = $text;

            return ['id' => 300];
        });
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(['id' => 301]);

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

    foreach ($postedBodies as $body) {
        expect($body)->toContain('🤖 cdv-rabbit (AI generated):');
    }
});

test('AC6: summary comment is also prefixed with AI marker', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 4]);
    $dto = makeReviewResult(commentCount: 0, riskLevel: 'low');

    $summaryText = null;
    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturnUsing(function ($slug, $pr, $text) use (&$summaryText) {
            $summaryText = $text;

            return ['id' => 400];
        });

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

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

    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('updateComment')
        ->once()
        ->with('org/repo', 5, 777, Mockery::type('string'));
    $client->shouldReceive('postInlineComment')->never();
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(['id' => 500]);

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

    // No duplicate inline row created.
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
    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postInlineComment')
        ->once()
        ->andReturnUsing(function ($slug, $pr, $text) use (&$postedInline) {
            $postedInline = $text;

            return ['id' => 600];
        });
    $client->shouldReceive('postPullRequestComment')
        ->once()
        ->andReturn(['id' => 601]);

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

    expect($postedInline)->not->toContain('@dev1')
        ->not->toContain('@qa-team');
});

test('review_comments rows contain no diff or code content columns', function (): void {
    $review = Review::factory()->create(['pull_request_number' => 7]);
    $dto = makeReviewResult(commentCount: 1, riskLevel: 'medium');

    $client = Mockery::mock(BitbucketClient::class);
    $client->shouldReceive('postInlineComment')->once()->andReturn(['id' => 700]);
    $client->shouldReceive('postPullRequestComment')->once()->andReturn(['id' => 701]);

    makeCommentPoster($client)->post($review, $dto, 'org/repo');

    $columns = ReviewComment::where('review_id', $review->id)->get()->map->getAttributes()->toArray();

    foreach ($columns as $row) {
        expect($row)->not->toHaveKey('content')
            ->not->toHaveKey('diff')
            ->not->toHaveKey('patch')
            ->not->toHaveKey('code');
    }
});
