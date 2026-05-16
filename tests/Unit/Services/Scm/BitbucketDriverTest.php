<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\Workspace;
use App\Services\Scm\BitbucketDriver;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeBitbucketWorkspace(string $token = 'test-token', string $slug = 'my-workspace', string $email = 'svc@example.com'): Workspace
{
    $workspace = Workspace::factory()->create([
        'scm_owner_slug' => $slug,
        'bitbucket_token' => $token,
        'bitbucket_service_account' => $email,
    ]);

    app(WorkspaceContext::class)->bind($workspace->id);

    return $workspace;
}

test('verifyCredentials returns valid CredentialCheck for 200 with account_id', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['account_id' => 'abc123', 'display_name' => 'Test User'], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $check = $driver->verifyCredentials();

    expect($check)->toBeInstanceOf(CredentialCheck::class);
    expect($check->valid)->toBeTrue();
    expect($check->identity)->toBe('bb-user:abc123');

    $expected = 'Basic '.base64_encode('svc@example.com:test-token');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/user')
        && $request->hasHeader('Authorization', $expected));
});

test('verifyCredentials returns invalid CredentialCheck for 401', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $check = $driver->verifyCredentials();

    expect($check->valid)->toBeFalse();
    expect($check->reason)->toContain('401');
});

test('listRepositories returns Collection of RepositoryDto', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace' => Http::response([
            'values' => [
                ['uuid' => '{uuid-1}', 'name' => 'repo-1', 'full_name' => 'my-workspace/repo-1', 'mainbranch' => ['name' => 'main'], 'is_private' => true],
                ['uuid' => '{uuid-2}', 'name' => 'repo-2', 'full_name' => 'my-workspace/repo-2', 'mainbranch' => ['name' => 'develop'], 'is_private' => false],
            ],
            'next' => null,
        ], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $result = $driver->listRepositories();

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(RepositoryDto::class);
    expect($result[0]->scmRepoId)->toBe('{uuid-1}');
    expect($result[0]->fullName)->toBe('my-workspace/repo-1');
    expect($result[0]->defaultBranch)->toBe('main');
    expect($result[1]->isPrivate)->toBeFalse();
});

test('getRepository returns RepositoryDto on 200', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo' => Http::response([
            'uuid' => '{uuid-9}',
            'name' => 'my-repo',
            'full_name' => 'my-workspace/my-repo',
            'mainbranch' => ['name' => 'main'],
        ], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    // No Repository row exists; resolveFullName falls back to the scmRepoId value, which the test treats as the full_name URL segment.
    $result = $driver->getRepository('my-workspace/my-repo');

    expect($result)->toBeInstanceOf(RepositoryDto::class);
    expect($result->fullName)->toBe('my-workspace/my-repo');
});

test('getRepository returns null on 404', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/missing-repo' => Http::response([], 404),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $result = $driver->getRepository('my-workspace/missing-repo');

    expect($result)->toBeNull();
});

test('getPullRequest returns PullRequestDto on 200', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/pullrequests/42' => Http::response([
            'id' => 42,
            'title' => 'My PR',
            'state' => 'OPEN',
            'source' => ['branch' => ['name' => 'feature-x'], 'commit' => ['hash' => 'aaaa']],
            'destination' => ['branch' => ['name' => 'main'], 'commit' => ['hash' => 'bbbb']],
            'author' => ['display_name' => 'Alice'],
        ], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $pr = $driver->getPullRequest('my-workspace/my-repo', 42);

    expect($pr)->toBeInstanceOf(PullRequestDto::class);
    expect($pr->number)->toBe(42);
    expect($pr->state)->toBe('OPEN');
    expect($pr->headSha)->toBe('aaaa');
    expect($pr->sourceBranch)->toBe('feature-x');
});

test('postInlineComment sends BB inline payload and returns CommentHandle', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/pullrequests/7/comments' => Http::response([
            'id' => 9999,
        ], 201),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $handle = $driver->postInlineComment('my-workspace/my-repo', 7, new InlineCommentPayload(
        body: 'hi',
        path: 'src/x.php',
        line: 12,
        headSha: 'deadbeef',
    ));

    expect($handle->scmCommentId)->toBe('9999');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/comments')
            && ($body['inline']['path'] ?? null) === 'src/x.php'
            && ($body['inline']['to'] ?? null) === 12;
    });
});

test('registerWebhook returns WebhookHandle with scmWebhookUuid', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/hooks' => Http::response([
            'uuid' => '{hook-uuid}',
            'url' => 'https://example.com/bb/webhook',
        ], 201),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $handle = $driver->registerWebhook(
        'my-workspace/my-repo',
        'https://example.com/bb/webhook',
        'my-secret',
    );

    expect($handle)->toBeInstanceOf(WebhookHandle::class);
    expect($handle->scmWebhookUuid)->toBe('{hook-uuid}');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/hooks')
            && $request->isJson()
            && $body['active'] === true
            && in_array('pullrequest:created', $body['events']);
    });
});

test('deleteWebhook is a no-op when handle is null', function (): void {
    Http::fake();

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $driver->deleteWebhook('my-workspace/my-repo', null);

    Http::assertNothingSent();
});

test('deleteWebhook sends DELETE to BB hooks endpoint', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/hooks/*' => Http::response(null, 204),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $driver->deleteWebhook('my-workspace/my-repo', new WebhookHandle(scmWebhookUuid: '{hook-uuid}'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hooks/'));
});

test('429 with Retry-After triggers retry then succeeds', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '1'])
            ->push(['account_id' => 'abc', 'display_name' => 'Retried'], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $check = $driver->verifyCredentials();

    expect($check->valid)->toBeTrue();
    Http::assertSentCount(2);
});

test('lastRateLimit captures rate limit headers', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(
            ['account_id' => 'abc'],
            200,
            ['X-RateLimit-Remaining' => '95', 'X-RateLimit-Limit' => '1000']
        ),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $driver->verifyCredentials();

    $rateLimit = $driver->lastRateLimit();

    expect($rateLimit)->not->toBeNull();
    expect($rateLimit['remaining'])->toBe(95);
    expect($rateLimit['limit'])->toBe(1000);
});

test('lastRateLimit is null when no rate limit headers present', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['account_id' => 'abc'], 200),
    ]);

    $driver = new BitbucketDriver(makeBitbucketWorkspace());
    $driver->verifyCredentials();

    expect($driver->lastRateLimit())->toBeNull();
});
