<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\WebhookDelivery;
use App\Models\Workspace;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeReviewWithChildren(Workspace $workspace, Repository $repository, DateTimeInterface $createdAt): Review
{
    app(WorkspaceContext::class)->bind($workspace->id);

    $review = Review::factory()->forRepository($repository)->create([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    ReviewComment::factory()->create([
        'review_id' => $review->id,
        'workspace_id' => $workspace->id,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $review;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('soft-deletes reviews older than soft_delete_days', function (): void {
    $workspace = Workspace::factory()->create();
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $old = makeReviewWithChildren($workspace, $repository, now()->subDays(400));
    $recent = makeReviewWithChildren($workspace, $repository, now()->subDays(10));

    $this->artisan('rabbit:purge-stale')->assertSuccessful();

    expect(Review::withoutWorkspaceScope()->withTrashed()->find($old->id)->deleted_at)->not->toBeNull()
        ->and(Review::withoutWorkspaceScope()->find($recent->id)->deleted_at)->toBeNull();
});

it('cascades soft-delete to review_comments and reviews_llm_calls', function (): void {
    $workspace = Workspace::factory()->create();
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $review = makeReviewWithChildren($workspace, $repository, now()->subDays(400));
    $commentId = ReviewComment::withoutWorkspaceScope()->where('review_id', $review->id)->first()->id;

    $this->artisan('rabbit:purge-stale')->assertSuccessful();

    expect(ReviewComment::withoutWorkspaceScope()->withTrashed()->find($commentId)->deleted_at)->not->toBeNull();
});

it('hard-deletes reviews soft-deleted longer than hard_delete_grace_days', function (): void {
    $workspace = Workspace::factory()->create();
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    app(WorkspaceContext::class)->bind($workspace->id);

    // Soft-delete the review manually with an old deleted_at
    $review = Review::factory()->forRepository($repository)->create([
        'created_at' => now()->subDays(400),
        'deleted_at' => now()->subDays(45),
    ]);

    ReviewComment::factory()->create([
        'review_id' => $review->id,
        'workspace_id' => $workspace->id,
        'deleted_at' => now()->subDays(45),
    ]);

    $this->artisan('rabbit:purge-stale')->assertSuccessful();

    expect(Review::withoutWorkspaceScope()->withTrashed()->find($review->id))->toBeNull();
    expect(ReviewComment::withoutWorkspaceScope()->withTrashed()->where('review_id', $review->id)->exists())->toBeFalse();
});

it('hard-deletes webhook_deliveries older than 90 days', function (): void {
    $repository = Repository::factory()->create();

    $old = WebhookDelivery::factory()->create([
        'repository_id' => $repository->id,
        'created_at' => now()->subDays(100),
    ]);

    $recent = WebhookDelivery::factory()->create([
        'repository_id' => $repository->id,
        'created_at' => now()->subDays(30),
    ]);

    $this->artisan('rabbit:purge-stale')->assertSuccessful();

    expect(WebhookDelivery::find($old->id))->toBeNull()
        ->and(WebhookDelivery::find($recent->id))->not->toBeNull();
});

it('--dry-run makes no changes', function (): void {
    $workspace = Workspace::factory()->create();
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    $old = makeReviewWithChildren($workspace, $repository, now()->subDays(400));

    $repository2 = Repository::factory()->create();
    $oldWebhook = WebhookDelivery::factory()->create([
        'repository_id' => $repository2->id,
        'created_at' => now()->subDays(100),
    ]);

    $this->artisan('rabbit:purge-stale --dry-run')->assertSuccessful();

    expect(Review::withoutWorkspaceScope()->find($old->id)->deleted_at)->toBeNull()
        ->and(WebhookDelivery::find($oldWebhook->id))->not->toBeNull();
});

it('runs across multiple workspaces', function (): void {
    $workspace1 = Workspace::factory()->create();
    $repository1 = Repository::factory()->forWorkspace($workspace1)->create();
    $old1 = makeReviewWithChildren($workspace1, $repository1, now()->subDays(400));

    $workspace2 = Workspace::factory()->create();
    $repository2 = Repository::factory()->forWorkspace($workspace2)->create();
    $old2 = makeReviewWithChildren($workspace2, $repository2, now()->subDays(400));

    $this->artisan('rabbit:purge-stale')->assertSuccessful();

    expect(Review::withoutWorkspaceScope()->withTrashed()->find($old1->id)->deleted_at)->not->toBeNull()
        ->and(Review::withoutWorkspaceScope()->withTrashed()->find($old2->id)->deleted_at)->not->toBeNull();
});
