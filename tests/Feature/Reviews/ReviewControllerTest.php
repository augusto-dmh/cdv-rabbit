<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\ReviewsLlmCall;
use App\Models\User;
use App\Models\Workspace;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

function makeWorkspaceWithMember(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($workspace->id);

    return [$workspace, $user];
}

// ── index ─────────────────────────────────────────────────────────────────

test('index returns own-workspace reviews only', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();

    $repo = Repository::factory()->for($workspace)->create();
    Review::factory()->forRepository($repo)->count(3)->create();

    $otherWorkspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->bind($otherWorkspace->id);
    $otherRepo = Repository::factory()->for($otherWorkspace)->create();
    Review::factory()->forRepository($otherRepo)->create();
    app(WorkspaceContext::class)->bind($workspace->id);

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('reviews/Index')
        ->where('reviews.total', 3)
    );
});

test('filter by repository_id narrows correctly', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();

    $repo1 = Repository::factory()->for($workspace)->create();
    $repo2 = Repository::factory()->for($workspace)->create();

    Review::factory()->forRepository($repo1)->count(2)->create();
    Review::factory()->forRepository($repo2)->count(3)->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug).'?repository_id='.$repo1->id);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('reviews.total', 2)
    );
});

test('filter by status narrows correctly', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();

    Review::factory()->forRepository($repo)->create(['status' => ReviewStatus::Posted]);
    Review::factory()->forRepository($repo)->create(['status' => ReviewStatus::Failed]);
    Review::factory()->forRepository($repo)->create(['status' => ReviewStatus::Queued]);

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug).'?status=posted');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('reviews.total', 1)
    );
});

test('date range filter is inclusive', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();

    Review::factory()->forRepository($repo)->create(['created_at' => '2026-01-01 12:00:00']);
    Review::factory()->forRepository($repo)->create(['created_at' => '2026-01-15 12:00:00']);
    Review::factory()->forRepository($repo)->create(['created_at' => '2026-01-31 12:00:00']);
    Review::factory()->forRepository($repo)->create(['created_at' => '2026-02-10 12:00:00']);

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug).'?date_from=2026-01-01&date_to=2026-01-31');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('reviews.total', 3)
    );
});

test('per_page is respected and max 100 enforced', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();

    Review::factory()->forRepository($repo)->count(30)->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug).'?per_page=10');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('reviews.per_page', 10)
        ->where('reviews.total', 30)
    );

    // per_page above max is capped at 100
    $response2 = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug).'?per_page=500');

    $response2->assertSessionHasErrors('per_page');
});

test('non-member gets 403 on index', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.index', $workspace->slug));

    $response->assertForbidden();
});

// ── show ──────────────────────────────────────────────────────────────────

test('show returns review with comments and llmCalls', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();
    $review = Review::factory()->forRepository($repo)->create(['cost_usd_cents' => 1234]);

    ReviewComment::factory()->forReview($review)->count(2)->create();
    ReviewsLlmCall::factory()->forReview($review)->count(1)->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.show', [$workspace->slug, $review->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('reviews/Show')
        ->has('comments', 2)
        ->has('llmCalls', 1)
    );
});

test('show returns 404 for review not in workspace', function (): void {
    [$workspace, $user] = makeWorkspaceWithMember();

    $otherWorkspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->bind($otherWorkspace->id);
    $otherRepo = Repository::factory()->for($otherWorkspace)->create();
    $otherReview = Review::factory()->forRepository($otherRepo)->create();
    app(WorkspaceContext::class)->bind($workspace->id);

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.show', [$workspace->slug, $otherReview->id]));

    $response->assertNotFound();
});

test('non-member gets 403 on show', function (): void {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->bind($workspace->id);
    $repo = Repository::factory()->for($workspace)->create();
    $review = Review::factory()->forRepository($repo)->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.reviews.show', [$workspace->slug, $review->id]));

    $response->assertForbidden();
});

// ── cost accessor ─────────────────────────────────────────────────────────

test('cost accessor formats cents as USD string', function (): void {
    [$workspace] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();

    $review = Review::factory()->forRepository($repo)->create(['cost_usd_cents' => 1234]);

    expect($review->cost_usd)->toBe('$12.3400');
});

test('cost accessor returns zero for null cost', function (): void {
    [$workspace] = makeWorkspaceWithMember();
    $repo = Repository::factory()->for($workspace)->create();

    $review = Review::factory()->forRepository($repo)->create(['cost_usd_cents' => 0]);

    expect($review->cost_usd)->toBe('$0.0000');
});
