<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

function defaultPayload(): array
{
    return [
        'pullrequest' => [
            'id' => 42,
            'source' => ['commit' => ['hash' => 'abc123def456']],
        ],
    ];
}

function signedWebhookCall(
    TestCase $test,
    Repository $repository,
    string $urlToken,
    array $payload,
    string $eventKey = 'pullrequest:created',
    ?string $hookUuid = null,
    ?string $overrideSignature = null,
): TestResponse {
    $content = json_encode($payload);
    $secret = $repository->workspace->webhook_secret;
    $signature = $overrideSignature ?? ('sha256='.hash_hmac('sha256', $content, $secret));
    $uuid = $hookUuid ?? Str::uuid()->toString();

    return $test->call(
        'POST',
        "/bb/webhook/{$repository->id}/{$urlToken}",
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
        $content
    );
}

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create([
        'webhook_secret' => 'super-secret-key',
    ]);

    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->repository = Repository::factory()->forWorkspace($this->workspace)->create([
        'webhook_token' => 'valid-url-token',
    ]);
});

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('valid HMAC and URL token dispatches job and returns 202', function (): void {
    Queue::fake();

    $hookUuid = Str::uuid()->toString();
    $response = signedWebhookCall($this, $this->repository, 'valid-url-token', defaultPayload(), hookUuid: $hookUuid);

    $response->assertStatus(202);

    Queue::assertPushed(ReviewPullRequestJob::class, function (ReviewPullRequestJob $job): bool {
        return $job->workspaceId === $this->workspace->id
            && $job->repositoryId === $this->repository->id
            && $job->pullRequestNumber === 42
            && $job->headSha === 'abc123def456';
    });

    expect(WebhookDelivery::where('repository_id', $this->repository->id)->count())->toBe(1);
    expect(WebhookDelivery::latest('id')->first()->status)->toBe(WebhookDeliveryStatus::Dispatched);
});

test('missing X-Hub-Signature returns 401', function (): void {
    $content = json_encode(defaultPayload());

    $response = $this->call(
        'POST',
        "/bb/webhook/{$this->repository->id}/valid-url-token",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVENT_KEY' => 'pullrequest:created',
            'HTTP_X_HOOK_UUID' => Str::uuid()->toString(),
        ],
        $content
    );

    $response->assertStatus(401);
});

test('wrong HMAC returns 401', function (): void {
    $response = signedWebhookCall(
        $this,
        $this->repository,
        'valid-url-token',
        defaultPayload(),
        overrideSignature: 'sha256=badhmacsignaturevalue'
    );

    $response->assertStatus(401);
});

test('wrong URL token returns 404', function (): void {
    $response = signedWebhookCall($this, $this->repository, 'wrong-token', defaultPayload());

    $response->assertStatus(404);
});

test('duplicate X-Hook-UUID returns 200 without dispatching job', function (): void {
    Queue::fake();

    $hookUuid = Str::uuid()->toString();

    WebhookDelivery::create([
        'scm_delivery_id' => $hookUuid,
        'repository_id' => $this->repository->id,
        'event_type' => 'pullrequest:created',
        'status' => WebhookDeliveryStatus::Dispatched,
        'created_at' => now(),
    ]);

    $response = signedWebhookCall($this, $this->repository, 'valid-url-token', defaultPayload(), hookUuid: $hookUuid);

    $response->assertStatus(200);
    $response->assertJsonFragment(['message' => 'duplicate']);

    Queue::assertNothingPushed();
    expect(WebhookDelivery::where('scm_delivery_id', $hookUuid)->count())->toBe(1);
});

test('non-pullrequest:created event returns 202 without dispatching job', function (): void {
    Queue::fake();

    $response = signedWebhookCall($this, $this->repository, 'valid-url-token', defaultPayload(), eventKey: 'repo:push');

    $response->assertStatus(202);
    $response->assertJsonFragment(['message' => 'event ignored']);

    Queue::assertNothingPushed();
    expect(WebhookDelivery::where('repository_id', $this->repository->id)->count())->toBe(0);
});
