<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function makeFakeWorkspace(string $token = 'test-token', string $slug = 'my-workspace', string $email = 'svc@example.com'): Workspace
{
    $workspace = Mockery::mock(Workspace::class)->makePartial();
    $workspace->bitbucket_token = $token;
    $workspace->bitbucket_workspace_slug = $slug;
    $workspace->bitbucket_service_account = $email;

    return $workspace;
}

test('me sends basic auth and returns user data', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['uuid' => '{abc-123}', 'display_name' => 'Test User'], 200),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->me();

    expect($result)->toHaveKey('uuid');
    expect($result['display_name'])->toBe('Test User');

    $expected = 'Basic '.base64_encode('svc@example.com:test-token');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/user')
        && $request->hasHeader('Authorization', $expected));
});

test('listRepositories returns paginated results merged into single array', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace' => Http::response([
            'values' => [['slug' => 'repo-1'], ['slug' => 'repo-2']],
            'next' => null,
        ], 200),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->listRepositories();

    expect($result)->toHaveCount(2);
    expect($result[0]['slug'])->toBe('repo-1');
});

test('getRepository returns data on 200', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo' => Http::response(['full_name' => 'my-workspace/my-repo'], 200),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->getRepository('my-workspace/my-repo');

    expect($result)->not->toBeNull();
    expect($result['full_name'])->toBe('my-workspace/my-repo');
});

test('getRepository returns null on 404', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/missing-repo' => Http::response([], 404),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->getRepository('my-workspace/missing-repo');

    expect($result)->toBeNull();
});

test('registerWebhook posts correct payload and returns response', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/hooks' => Http::response([
            'uuid' => '{hook-uuid}',
            'url' => 'https://example.com/bb/webhook',
        ], 201),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->registerWebhook(
        'my-workspace/my-repo',
        'https://example.com/bb/webhook',
        'my-secret',
        ['pullrequest:created']
    );

    expect($result['uuid'])->toBe('{hook-uuid}');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/hooks')
            && $request->isJson()
            && $body['active'] === true
            && in_array('pullrequest:created', $body['events']);
    });
});

test('deleteWebhook returns true on success', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/my-repo/hooks/*' => Http::response(null, 204),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->deleteWebhook('my-workspace/my-repo', '{hook-uuid}');

    expect($result)->toBeTrue();
});

test('429 with Retry-After triggers retry then succeeds', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '1'])
            ->push(['uuid' => '{abc}', 'display_name' => 'Retried'], 200),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $result = $client->me();

    expect($result['display_name'])->toBe('Retried');
    Http::assertSentCount(2);
});

test('lastRateLimit captures rate limit headers', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(
            ['uuid' => '{abc}'],
            200,
            ['X-RateLimit-Remaining' => '95', 'X-RateLimit-Limit' => '1000']
        ),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $client->me();

    $rateLimit = $client->lastRateLimit();

    expect($rateLimit)->not->toBeNull();
    expect($rateLimit['remaining'])->toBe(95);
    expect($rateLimit['limit'])->toBe(1000);
});

test('lastRateLimit is null when no rate limit headers present', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['uuid' => '{abc}'], 200),
    ]);

    $client = new BitbucketClient(makeFakeWorkspace());
    $client->me();

    expect($client->lastRateLimit())->toBeNull();
});
