<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Github\InstallationTokenCache;
use App\Services\Scm\Github\JwtSigner;
use App\Services\Scm\GithubDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Generate a throwaway RSA keypair once per test run so JWT signing works without
 * needing a real GitHub App private key.
 */
function githubTestPrivateKey(): string
{
    static $key = null;

    if ($key === null) {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $key);
    }

    return $key;
}

function makeGithubDriver(string $installationId = '12345'): GithubDriver
{
    config()->set('services.github.base_url', 'https://api.github.com');
    config()->set('services.github.app_id', '111');
    config()->set('services.github.app_private_key', githubTestPrivateKey());

    $workspace = Workspace::factory()->create([
        'scm_provider' => 'github_cloud',
        'scm_owner_slug' => 'octocat',
        'github_installation_id' => $installationId,
        'bitbucket_token' => null,
        'bitbucket_service_account' => null,
    ]);

    app(WorkspaceContext::class)->bind($workspace->id);

    // Pre-seed the installation token cache so we don't hit the token-exchange endpoint
    // in every test. Real flow goes through Http::fake for the exchange separately.
    Cache::put("scm:github:installation_token:{$installationId}", 'ghs_test_token', 3000);

    return new GithubDriver(
        workspace: $workspace,
        tokenCache: app(InstallationTokenCache::class),
    );
}

test('verifyCredentials returns valid CredentialCheck on 200 from /installation/repositories', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => []], 200),
    ]);

    $check = makeGithubDriver()->verifyCredentials();

    expect($check)->toBeInstanceOf(CredentialCheck::class);
    expect($check->valid)->toBeTrue();
    expect($check->identity)->toContain('gh-installation:');
});

test('verifyCredentials returns invalid CredentialCheck on 401', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $check = makeGithubDriver()->verifyCredentials();

    expect($check->valid)->toBeFalse();
    expect($check->reason)->toContain('401');
});

test('listRepositories returns Collection of RepositoryDto', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                [
                    'id' => 1001,
                    'name' => 'repo-a',
                    'full_name' => 'octocat/repo-a',
                    'owner' => ['login' => 'octocat'],
                    'default_branch' => 'main',
                    'private' => false,
                ],
                [
                    'id' => 1002,
                    'name' => 'repo-b',
                    'full_name' => 'octocat/repo-b',
                    'owner' => ['login' => 'octocat'],
                    'default_branch' => 'develop',
                    'private' => true,
                ],
            ],
        ], 200),
    ]);

    $result = makeGithubDriver()->listRepositories();

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(RepositoryDto::class);
    expect($result[0]->scmRepoId)->toBe('1001');
    expect($result[0]->fullName)->toBe('octocat/repo-a');
    expect($result[1]->isPrivate)->toBeTrue();
});

test('getPullRequest returns PullRequestDto with mapped fields', function (): void {
    Http::fake([
        'api.github.com/repos/octocat/hello/pulls/7' => Http::response([
            'number' => 7,
            'title' => 'Fix bug',
            'state' => 'open',
            'head' => ['ref' => 'feature-x', 'sha' => 'aaaa'],
            'base' => ['ref' => 'main', 'sha' => 'bbbb'],
            'user' => ['login' => 'alice'],
        ], 200),
    ]);

    $driver = makeGithubDriver();
    $workspace = $driver->verifyCredentials(); // primes workspace state
    Repository::create([
        'workspace_id' => Workspace::first()?->id,
        'scm_repo_id' => 'gh-pr-id',
        'name' => 'hello',
        'full_name' => 'octocat/hello',
        'default_branch' => 'main',
        'enabled' => true,
    ]);

    Http::fake([
        'api.github.com/repos/octocat/hello/pulls/7' => Http::response([
            'number' => 7,
            'title' => 'Fix bug',
            'state' => 'open',
            'head' => ['ref' => 'feature-x', 'sha' => 'aaaa'],
            'base' => ['ref' => 'main', 'sha' => 'bbbb'],
            'user' => ['login' => 'alice'],
        ], 200),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []], 200),
    ]);

    $pr = $driver->getPullRequest('gh-pr-id', 7);

    expect($pr)->toBeInstanceOf(PullRequestDto::class);
    expect($pr->number)->toBe(7);
    expect($pr->state)->toBe('OPEN');
    expect($pr->headSha)->toBe('aaaa');
    expect($pr->sourceBranch)->toBe('feature-x');
});

test('postInlineComment sends commit_id + path + line and returns CommentHandle', function (): void {
    Http::fake([
        'api.github.com/repos/octocat/hello/pulls/3/comments' => Http::response(['id' => 9009], 201),
    ]);

    $driver = makeGithubDriver();
    Repository::create([
        'workspace_id' => Workspace::first()?->id,
        'scm_repo_id' => 'gh-id-3',
        'name' => 'hello',
        'full_name' => 'octocat/hello',
        'default_branch' => 'main',
        'enabled' => true,
    ]);

    $handle = $driver->postInlineComment('gh-id-3', 3, new InlineCommentPayload(
        body: 'Looks risky',
        path: 'src/x.php',
        line: 42,
        headSha: 'deadbeef',
    ));

    expect($handle)->toBeInstanceOf(CommentHandle::class);
    expect($handle->scmCommentId)->toBe('9009');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/pulls/3/comments')
            && ($body['commit_id'] ?? null) === 'deadbeef'
            && ($body['path'] ?? null) === 'src/x.php'
            && ($body['line'] ?? null) === 42
            && ($body['side'] ?? null) === 'RIGHT';
    });
});

test('registerWebhook is a no-op for GitHub App (returns null)', function (): void {
    Http::fake();

    $handle = makeGithubDriver()->registerWebhook('any-id', 'https://example.com/cb', 'secret');

    expect($handle)->toBeNull();
    Http::assertNothingSent();
});

test('deleteWebhook is a no-op for GitHub App', function (): void {
    Http::fake();

    makeGithubDriver()->deleteWebhook('any-id', null);

    Http::assertNothingSent();
});

test('lastRateLimit captures GitHub rate-limit headers', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response(
            ['repositories' => []],
            200,
            [
                'X-RateLimit-Remaining' => '4998',
                'X-RateLimit-Limit' => '5000',
                'X-RateLimit-Reset' => '1700000000',
            ]
        ),
    ]);

    $driver = makeGithubDriver();
    $driver->verifyCredentials();

    $rateLimit = $driver->lastRateLimit();

    expect($rateLimit['remaining'])->toBe(4998);
    expect($rateLimit['limit'])->toBe(5000);
    expect($rateLimit['reset'])->toBe(1700000000);
});

test('JwtSigner mints a 3-segment RS256 token verifiable with the public key', function (): void {
    $privateKey = githubTestPrivateKey();
    $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['key'];

    $signer = new JwtSigner(appId: '222', privateKey: $privateKey, ttlSeconds: 300);
    $jwt = $signer->mint();

    [$headerB64, $payloadB64, $sigB64] = explode('.', $jwt);
    $signingInput = "{$headerB64}.{$payloadB64}";
    $signature = base64_decode(strtr($sigB64, '-_', '+/'));

    expect(openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
    expect($payload['iss'])->toBe('222');
    expect($payload['exp'])->toBeGreaterThan(time());
});
