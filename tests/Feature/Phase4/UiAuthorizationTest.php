<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use App\Models\Workspace;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->admin = User::factory()->create();
    $this->workspace->users()->attach($this->admin->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->repo = Repository::factory()->for($this->workspace)->create();
    $this->review = Review::factory()->forRepository($this->repo)->create();
});

// Unauthenticated redirects

test('unauthenticated visit to workspaces index redirects to login', function (): void {
    $this->get(route('workspaces.index'))->assertRedirect(route('login'));
});

test('unauthenticated visit to reviews index redirects to login', function (): void {
    $this->get(route('workspaces.reviews.index', $this->workspace->slug))
        ->assertRedirect(route('login'));
});

test('unauthenticated visit to reviews show redirects to login', function (): void {
    $this->get(route('workspaces.reviews.show', [$this->workspace->slug, $this->review->id]))
        ->assertRedirect(route('login'));
});

test('unauthenticated visit to kill switch redirects to login', function (): void {
    $this->get(route('workspaces.admin.kill-switch.edit', $this->workspace->slug))
        ->assertRedirect(route('login'));
});

// Non-member 403

test('authenticated non-member visiting reviews index gets 403', function (): void {
    $nonMember = User::factory()->create();

    $this->actingAs($nonMember)
        ->get(route('workspaces.reviews.index', $this->workspace->slug))
        ->assertForbidden();
});

test('authenticated non-member visiting reviews show gets 403', function (): void {
    $nonMember = User::factory()->create();

    $this->actingAs($nonMember)
        ->get(route('workspaces.reviews.show', [$this->workspace->slug, $this->review->id]))
        ->assertForbidden();
});

test('authenticated non-member visiting kill switch gets 403', function (): void {
    $nonMember = User::factory()->create();

    $this->actingAs($nonMember)
        ->get(route('workspaces.admin.kill-switch.edit', $this->workspace->slug))
        ->assertForbidden();
});

// Member access

test('member can list their workspace reviews', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.index', $this->workspace->slug))
        ->assertOk();
});

test('member can show their workspace review', function (): void {
    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.show', [$this->workspace->slug, $this->review->id]))
        ->assertOk();
});

// Cross-workspace 404 via global scope

test('member cannot view another workspace review — 404 via global scope', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->bind($otherWorkspace->id);
    $otherRepo = Repository::factory()->for($otherWorkspace)->create();
    $otherReview = Review::factory()->forRepository($otherRepo)->create();
    app(WorkspaceContext::class)->bind($this->workspace->id);

    $this->actingAs($this->admin)
        ->get(route('workspaces.reviews.show', [$this->workspace->slug, $otherReview->id]))
        ->assertNotFound();
});
