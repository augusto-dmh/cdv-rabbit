<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

function bbWebhookCall(
    TestCase $test,
    Repository $repository,
    string $webhookToken,
    string $jsonContent,
    string $eventKey = 'pullrequest:created',
    ?string $hookUuid = null,
): TestResponse {
    $secret = $repository->workspace->webhook_secret;
    $signature = 'sha256='.hash_hmac('sha256', $jsonContent, $secret);
    $uuid = $hookUuid ?? Str::uuid()->toString();

    return $test->call(
        'POST',
        "/bb/webhook/{$repository->id}/{$webhookToken}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE' => $signature,
            'HTTP_X_EVENT_KEY' => $eventKey,
            'HTTP_X_HOOK_UUID' => $uuid,
        ],
        $jsonContent
    );
}

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create([
        'webhook_secret' => 'e2e-smoke-secret',
    ]);

    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->repository = Repository::factory()->forWorkspace($this->workspace)->create([
        'webhook_token' => 'smoke-webhook-token',
        'enabled' => true,
    ]);

    $this->fixture = file_get_contents(
        base_path('tests/Fixtures/bitbucket/pr_created.json')
    );
});

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('pullrequest:created webhook end-to-end: 202 + delivery row + job dispatched', function (): void {
    Queue::fake();

    $hookUuid = Str::uuid()->toString();
    $response = bbWebhookCall($this, $this->repository, 'smoke-webhook-token', $this->fixture, hookUuid: $hookUuid);

    $response->assertStatus(202);
    $response->assertJsonStructure(['delivery_id']);

    $delivery = WebhookDelivery::where('scm_delivery_id', $hookUuid)->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Dispatched);
    expect($delivery->repository_id)->toBe($this->repository->id);
    expect($delivery->event_type)->toBe('pullrequest:created');

    Queue::assertPushed(ReviewPullRequestJob::class, function (ReviewPullRequestJob $job): bool {
        return $job->workspaceId === $this->workspace->id
            && $job->repositoryId === $this->repository->id
            && $job->pullRequestNumber === 7
            && $job->headSha === 'aabbccddeeff00112233445566778899aabbccdd';
    });
});

test('duplicate delivery: second POST with same X-Hook-UUID returns 200 and does not dispatch job', function (): void {
    Queue::fake();

    $hookUuid = Str::uuid()->toString();

    bbWebhookCall($this, $this->repository, 'smoke-webhook-token', $this->fixture, hookUuid: $hookUuid);
    Queue::assertPushed(ReviewPullRequestJob::class);
    Queue::clearResolvedInstances();

    Queue::fake();
    $second = bbWebhookCall($this, $this->repository, 'smoke-webhook-token', $this->fixture, hookUuid: $hookUuid);

    $second->assertStatus(200);
    $second->assertJsonFragment(['message' => 'duplicate']);

    Queue::assertNothingPushed();
    expect(WebhookDelivery::where('scm_delivery_id', $hookUuid)->count())->toBe(1);
});

test('GET /up returns 200', function (): void {
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 200),
        'https://api.anthropic.com/*' => Http::response('', 200),
    ]);

    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andReturn(['supervisor-1']);
    $horizonMock->shouldReceive('zscore')->andReturn((string) time());
    Redis::shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);

    $this->get('/up')->assertStatus(200);
});
