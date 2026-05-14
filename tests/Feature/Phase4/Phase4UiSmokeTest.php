<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use App\Models\Workspace;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    $this->admin = User::factory()->create();
    $this->workspace->users()->attach($this->admin->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->repo = Repository::factory()->for($this->workspace)->create();
    $this->review = Review::factory()->forRepository($this->repo)->create([
        'status' => ReviewStatus::Posted,
        'cost_usd_cents' => 500,
    ]);
});

test('workspaces index returns 200', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Index'));
});

test('workspace show returns 200', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.show', $this->workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Show'));
});

test('reviews index returns 200', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.index', $this->workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('reviews/Index'));
});

test('reviews show returns 200', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.show', [$this->workspace->slug, $this->review->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('reviews/Show'));
});

test('kill switch page returns 200', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.admin.kill-switch.edit', $this->workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/KillSwitch'));
});

test('reviews index filter by status returns only matching reviews', function (): void {
    Review::factory()->forRepository($this->repo)->create(['status' => ReviewStatus::Failed]);
    Review::factory()->forRepository($this->repo)->create(['status' => ReviewStatus::Queued]);

    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.index', $this->workspace->slug).'?status=posted')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reviews.total', 1));
});

test('reviews index filter by repository_id returns only that repo reviews', function (): void {
    $otherRepo = Repository::factory()->for($this->workspace)->create();
    Review::factory()->forRepository($otherRepo)->count(2)->create();

    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.index', $this->workspace->slug).'?repository_id='.$this->repo->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reviews.total', 1));
});

test('reviews index pagination next page works', function (): void {
    Review::factory()->forRepository($this->repo)->count(30)->create();

    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.index', $this->workspace->slug).'?per_page=10&page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reviews.current_page', 2)
            ->where('reviews.per_page', 10)
        );
});
