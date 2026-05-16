<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('index returns only workspaces the user belongs to', function (): void {
    $ownWorkspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $ownWorkspace->users()->attach($this->user->id, ['role' => 'admin']);

    $otherWorkspace = Workspace::factory()->create();

    $this->actingAs($this->user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Index')
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', $ownWorkspace->slug)
        );
});

test('store creates workspace and auto-attaches owner as admin', function (): void {
    $this->actingAs($this->user)
        ->post(route('workspaces.store'), [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'scm_provider' => 'bitbucket_cloud',
        ])
        ->assertRedirect(route('workspaces.show', 'acme-corp'));

    $workspace = Workspace::where('slug', 'acme-corp')->firstOrFail();

    expect($workspace->owner_id)->toBe($this->user->id);

    expect(
        $workspace->users()->where('user_id', $this->user->id)->where('role', 'admin')->exists()
    )->toBeTrue();
});

test('store rejects duplicate slug', function (): void {
    Workspace::factory()->create(['slug' => 'taken-slug']);

    $this->actingAs($this->user)
        ->post(route('workspaces.store'), [
            'name' => 'Another',
            'slug' => 'taken-slug',
            'scm_provider' => 'bitbucket_cloud',
        ])
        ->assertSessionHasErrors('slug');
});

test('store rejects invalid slug format', function (): void {
    $this->actingAs($this->user)
        ->post(route('workspaces.store'), [
            'name' => 'Bad Slug',
            'slug' => 'Bad Slug!',
            'scm_provider' => 'bitbucket_cloud',
        ])
        ->assertSessionHasErrors('slug');
});

test('show returns workspace with repositories for member', function (): void {
    $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $workspace->users()->attach($this->user->id, ['role' => 'admin']);

    $this->actingAs($this->user)
        ->get(route('workspaces.show', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Show')
            ->has('workspace')
            ->has('repositories')
        );
});

test('show returns 403 for non-member', function (): void {
    $workspace = Workspace::factory()->create();

    $this->actingAs($this->user)
        ->get(route('workspaces.show', $workspace->slug))
        ->assertForbidden();
});

test('guest is redirected from index', function (): void {
    $this->get(route('workspaces.index'))->assertRedirect(route('login'));
});
