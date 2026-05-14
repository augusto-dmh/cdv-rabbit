<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('shared props include workspaces list for authenticated user', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', $workspace->slug)
        );
});

test('currentWorkspace is null on non-workspace route', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentWorkspace', null));
});

test('currentWorkspace is populated on workspace-scoped route', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($workspace->id);

    $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentWorkspace.slug', $workspace->slug)
        );
});

test('currentWorkspaceKillSwitchEnabled is null on non-workspace route', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentWorkspaceKillSwitchEnabled', null));
});

test('currentWorkspaceKillSwitchEnabled reflects workspace state on workspace route', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => true]);
    $workspace->users()->attach($user->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($workspace->id);

    $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentWorkspaceKillSwitchEnabled', true));
});
