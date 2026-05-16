<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->admin->id,
        'scm_owner_slug' => 'my-workspace',
        'bitbucket_token' => str_repeat('t', 32),
        'bitbucket_service_account' => 'svc-acct',
    ]);
    $this->workspace->users()->attach($this->admin->id, ['role' => 'admin']);

    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->repository = Repository::factory()->forWorkspace($this->workspace)->create([
        'full_name' => 'my-workspace/repo-one',
        'enabled' => false,
        'scm_webhook_uuid' => null,
        'webhook_token' => null,
    ]);

    Http::preventStrayRequests();
});

test('enable registers webhook and persists scm_webhook_uuid and webhook_token', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/repo-one/hooks' => Http::response([
            'uuid' => '{webhook-uuid-abc}',
        ], 201),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('workspaces.repositories.update', [$this->workspace->slug, $this->repository->id]), [
            'enabled' => true,
        ])
        ->assertRedirect(route('workspaces.show', $this->workspace->slug));

    app(WorkspaceContext::class)->bind($this->workspace->id);
    $this->repository->refresh();

    expect($this->repository->enabled)->toBeTrue();
    expect($this->repository->scm_webhook_uuid)->toBe('{webhook-uuid-abc}');
    expect($this->repository->webhook_token)->not->toBeNull();
    expect(strlen($this->repository->webhook_token))->toBe(40);
});

test('disable deletes webhook and nulls webhook fields', function (): void {
    $this->repository->update([
        'enabled' => true,
        'scm_webhook_uuid' => 'abc-webhook-uuid-xyz',
        'webhook_token' => str_repeat('w', 40),
    ]);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace/repo-one/hooks/abc-webhook-uuid-xyz' => Http::response(null, 204),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('workspaces.repositories.update', [$this->workspace->slug, $this->repository->id]), [
            'enabled' => false,
        ])
        ->assertRedirect(route('workspaces.show', $this->workspace->slug));

    app(WorkspaceContext::class)->bind($this->workspace->id);
    $this->repository->refresh();

    expect($this->repository->enabled)->toBeFalse();
    expect($this->repository->scm_webhook_uuid)->toBeNull();
    expect($this->repository->webhook_token)->toBeNull();
});

test('enable is idempotent when repository is already enabled', function (): void {
    $this->repository->update([
        'enabled' => true,
        'scm_webhook_uuid' => 'existing-uuid-abc',
        'webhook_token' => str_repeat('e', 40),
    ]);

    // No HTTP fake registered — stray request would fail the test
    $this->actingAs($this->admin)
        ->patch(route('workspaces.repositories.update', [$this->workspace->slug, $this->repository->id]), [
            'enabled' => true,
        ])
        ->assertRedirect(route('workspaces.show', $this->workspace->slug));

    app(WorkspaceContext::class)->bind($this->workspace->id);
    $this->repository->refresh();

    expect($this->repository->scm_webhook_uuid)->toBe('existing-uuid-abc');
});

test('update returns 403 for non-member', function (): void {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->patch(route('workspaces.repositories.update', [$this->workspace->slug, $this->repository->id]), [
            'enabled' => true,
        ])
        ->assertForbidden();
});

test('update rejects missing enabled field', function (): void {
    $this->actingAs($this->admin)
        ->patch(route('workspaces.repositories.update', [$this->workspace->slug, $this->repository->id]), [])
        ->assertSessionHasErrors('enabled');
});
