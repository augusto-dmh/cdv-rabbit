<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\WorkspaceHealth;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.github.app_webhook_secret', 'gh-app-webhook-secret');
});

function makeGithubWorkspaceWithRepo(): array
{
    $workspace = Workspace::factory()->create([
        'scm_provider' => 'github_cloud',
        'scm_owner_slug' => 'octocat',
        'github_installation_id' => '777',
        'bitbucket_token' => null,
        'webhook_secret' => null,
    ]);

    app(WorkspaceContext::class)->bind($workspace->id);

    $repository = Repository::create([
        'workspace_id' => $workspace->id,
        'scm_repo_id' => '12345',
        'name' => 'hello',
        'full_name' => 'octocat/hello',
        'default_branch' => 'main',
        'enabled' => true,
    ]);

    return [$workspace, $repository];
}

function signGithubPayload(string $body): string
{
    return 'sha256='.hash_hmac('sha256', $body, 'gh-app-webhook-secret');
}

function pullRequestOpenedPayload(string $repoId = '12345', int $prNumber = 99, string $headSha = 'cafebabe'): array
{
    return [
        'action' => 'opened',
        'repository' => ['id' => (int) $repoId, 'full_name' => 'octocat/hello'],
        'pull_request' => [
            'number' => $prNumber,
            'head' => ['sha' => $headSha, 'ref' => 'feature'],
            'base' => ['sha' => 'deadbeef', 'ref' => 'main'],
            'title' => 'Fix bug',
            'user' => ['login' => 'alice'],
            'state' => 'open',
        ],
    ];
}

test('AC33: pull_request.opened with valid HMAC dispatches ReviewPullRequestJob and inserts webhook_delivery', function (): void {
    Queue::fake();
    [$workspace, $repository] = makeGithubWorkspaceWithRepo();

    $body = json_encode(pullRequestOpenedPayload());
    $response = $this->postJson('/scm/github/webhook', json_decode($body, true), [
        'X-Hub-Signature-256' => signGithubPayload($body),
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-1',
    ]);

    $response->assertStatus(202);
    expect(WebhookDelivery::where('scm_delivery_id', 'delivery-1')->where('scm_provider', 'github_cloud')->exists())->toBeTrue();
    Queue::assertPushed(ReviewPullRequestJob::class, function (ReviewPullRequestJob $job) use ($repository): bool {
        return $job->repositoryId === $repository->id && $job->pullRequestNumber === 99;
    });
});

test('AC34: missing X-Hub-Signature-256 returns 401 and does not dispatch', function (): void {
    Queue::fake();
    makeGithubWorkspaceWithRepo();

    $response = $this->postJson('/scm/github/webhook', pullRequestOpenedPayload(), [
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-no-sig',
    ]);

    $response->assertStatus(401);
    Queue::assertNothingPushed();
    expect(WebhookDelivery::count())->toBe(0);
});

test('AC34: wrong X-Hub-Signature-256 returns 401 and does not dispatch', function (): void {
    Queue::fake();
    makeGithubWorkspaceWithRepo();

    $response = $this->postJson('/scm/github/webhook', pullRequestOpenedPayload(), [
        'X-Hub-Signature-256' => 'sha256=00ff',
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-bad-sig',
    ]);

    $response->assertStatus(401);
    Queue::assertNothingPushed();
    expect(WebhookDelivery::count())->toBe(0);
});

test('AC35: duplicate X-GitHub-Delivery returns 200 and does not dispatch a second time', function (): void {
    Queue::fake();
    makeGithubWorkspaceWithRepo();

    $body = json_encode(pullRequestOpenedPayload(prNumber: 100));
    $signature = signGithubPayload($body);

    $first = $this->postJson('/scm/github/webhook', json_decode($body, true), [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-dup',
    ]);
    $first->assertStatus(202);

    $second = $this->postJson('/scm/github/webhook', json_decode($body, true), [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-dup',
    ]);

    $second->assertStatus(200);
    expect(WebhookDelivery::where('scm_delivery_id', 'delivery-dup')->count())->toBe(1);
    Queue::assertPushed(ReviewPullRequestJob::class, 1);
});

test('pull_request action other than opened returns 202 with event ignored', function (): void {
    Queue::fake();
    makeGithubWorkspaceWithRepo();

    $payload = pullRequestOpenedPayload();
    $payload['action'] = 'synchronize';
    $body = json_encode($payload);

    $response = $this->postJson('/scm/github/webhook', $payload, [
        'X-Hub-Signature-256' => signGithubPayload($body),
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-sync',
    ]);

    $response->assertStatus(202);
    Queue::assertNothingPushed();
    expect(WebhookDelivery::count())->toBe(0);
});

test('AC36: installation.deleted nullifies installation_id, marks unhealthy, disables repos (idempotent)', function (): void {
    Queue::fake();
    [$workspace, $repository] = makeGithubWorkspaceWithRepo();

    $payload = ['action' => 'deleted', 'installation' => ['id' => 777]];
    $body = json_encode($payload);

    $response = $this->postJson('/scm/github/webhook', $payload, [
        'X-Hub-Signature-256' => signGithubPayload($body),
        'X-GitHub-Event' => 'installation',
        'X-GitHub-Delivery' => 'delivery-install',
    ]);

    $response->assertStatus(202);
    $workspace->refresh();
    $repository->refresh();
    expect($workspace->github_installation_id)->toBeNull();
    expect($workspace->health)->toBe(WorkspaceHealth::Unhealthy);
    expect($repository->enabled)->toBeFalse();

    // Second delivery is idempotent.
    $response2 = $this->postJson('/scm/github/webhook', $payload, [
        'X-Hub-Signature-256' => signGithubPayload($body),
        'X-GitHub-Event' => 'installation',
        'X-GitHub-Delivery' => 'delivery-install-2',
    ]);
    $response2->assertStatus(202);
});

test('webhook for an unknown repository_id returns 202 and does not dispatch', function (): void {
    Queue::fake();
    makeGithubWorkspaceWithRepo();

    $payload = pullRequestOpenedPayload(repoId: '999999');
    $body = json_encode($payload);

    $response = $this->postJson('/scm/github/webhook', $payload, [
        'X-Hub-Signature-256' => signGithubPayload($body),
        'X-GitHub-Event' => 'pull_request',
        'X-GitHub-Delivery' => 'delivery-unknown',
    ]);

    $response->assertStatus(202);
    Queue::assertNothingPushed();
});
