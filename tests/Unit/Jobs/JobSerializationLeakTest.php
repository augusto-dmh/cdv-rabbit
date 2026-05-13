<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

class StubReviewPullRequestJob
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $repositoryId,
        public readonly int $pullRequestNumber,
        public readonly string $headSha,
    ) {}
}

test('serialized job contains no diff-shaped content and is under 2KB', function (): void {
    $job = new StubReviewPullRequestJob(
        workspaceId: 1,
        repositoryId: 2,
        pullRequestNumber: 42,
        headSha: 'abc123def456',
    );

    $serialized = serialize($job);

    expect($serialized)->not->toMatch('/(\+\+\+|---|@@)/m');
    expect(strlen($serialized))->toBeLessThan(2048);
});

test('serialized job does not contain diff, patch, content, body, or hunk keys', function (): void {
    $job = new StubReviewPullRequestJob(
        workspaceId: 1,
        repositoryId: 2,
        pullRequestNumber: 42,
        headSha: 'abc123def456',
    );

    $serialized = serialize($job);

    foreach (['diff', 'patch', 'content', 'body', 'hunk'] as $key) {
        expect($serialized)->not->toContain('"'.$key.'"');
    }
});
