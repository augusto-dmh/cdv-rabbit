<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->admin->id,
        'bitbucket_token' => null,
        'bitbucket_service_account' => null,
    ]);
    $this->workspace->users()->attach($this->admin->id, ['role' => 'admin']);

    Http::preventStrayRequests();
});

test('edit shows connect page for workspace member', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.connect.edit', $this->workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Connect')
            ->has('workspace')
            ->has('isConnected')
        );
});

test('edit returns 403 for non-member', function (): void {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(route('workspaces.connect.edit', $this->workspace->slug))
        ->assertForbidden();
});

test('update saves token when bitbucket api returns valid account', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['account_id' => 'abc123', 'username' => 'svc-acct'], 200),
    ]);

    $this->actingAs($this->admin)
        ->put(route('workspaces.connect.update', $this->workspace->slug), [
            'bitbucket_workspace_slug' => 'my-workspace',
            'bitbucket_token' => str_repeat('x', 20),
            'bitbucket_service_account' => 'svc-acct',
        ])
        ->assertRedirect(route('workspaces.connect.edit', $this->workspace->slug));

    $this->workspace->refresh();

    expect($this->workspace->bitbucket_service_account)->toBe('svc-acct');
    expect($this->workspace->bitbucket_workspace_slug)->toBe('my-workspace');
    expect($this->workspace->bitbucket_token)->not->toBeNull();
});

test('token is stored encrypted at rest', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['account_id' => 'abc123'], 200),
    ]);

    $rawToken = str_repeat('t', 32);

    $this->actingAs($this->admin)
        ->put(route('workspaces.connect.update', $this->workspace->slug), [
            'bitbucket_workspace_slug' => 'my-ws',
            'bitbucket_token' => $rawToken,
            'bitbucket_service_account' => 'svc',
        ]);

    $raw = DB::table('workspaces')
        ->where('id', $this->workspace->id)
        ->value('bitbucket_token');

    expect($raw)->not->toBe($rawToken);
    expect($this->workspace->refresh()->bitbucket_token)->toBe($rawToken);
});

test('update returns validation error when bitbucket returns 401', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->actingAs($this->admin)
        ->put(route('workspaces.connect.update', $this->workspace->slug), [
            'bitbucket_workspace_slug' => 'my-workspace',
            'bitbucket_token' => str_repeat('x', 20),
            'bitbucket_service_account' => 'svc',
        ])
        ->assertSessionHasErrors('bitbucket_token');

    expect($this->workspace->refresh()->bitbucket_token)->toBeNull();
});

test('update returns 403 for non-member', function (): void {
    $nonMember = User::factory()->create();

    $this->actingAs($nonMember)
        ->put(route('workspaces.connect.update', $this->workspace->slug), [
            'bitbucket_workspace_slug' => 'ws',
            'bitbucket_token' => str_repeat('x', 20),
            'bitbucket_service_account' => 'svc',
        ])
        ->assertForbidden();
});

test('destroy clears bitbucket token', function (): void {
    $this->workspace->update([
        'bitbucket_token' => str_repeat('x', 32),
        'bitbucket_service_account' => 'svc',
    ]);

    $this->actingAs($this->admin)
        ->delete(route('workspaces.connect.destroy', $this->workspace->slug))
        ->assertRedirect(route('workspaces.connect.edit', $this->workspace->slug));

    $this->workspace->refresh();

    expect($this->workspace->bitbucket_token)->toBeNull();
    expect($this->workspace->bitbucket_service_account)->toBeNull();
});

test('update rejects token shorter than 20 characters', function (): void {
    $this->actingAs($this->admin)
        ->put(route('workspaces.connect.update', $this->workspace->slug), [
            'bitbucket_workspace_slug' => 'ws',
            'bitbucket_token' => 'short',
            'bitbucket_service_account' => 'svc',
        ])
        ->assertSessionHasErrors('bitbucket_token');
});
