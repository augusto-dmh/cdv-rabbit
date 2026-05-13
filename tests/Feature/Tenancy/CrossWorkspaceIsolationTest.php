<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Exceptions\WorkspaceContextMissingException;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\ReviewsLlmCall;
use App\Models\Workspace;

beforeEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('each workspace only sees its own repositories', function (): void {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $repoA = Repository::factory()->forWorkspace($workspaceA)->create();

    app(WorkspaceContext::class)->bind($workspaceB->id);
    $repoB = Repository::factory()->forWorkspace($workspaceB)->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $results = Repository::all();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($repoA->id);
});

test('each workspace only sees its own reviews', function (): void {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $repoA = Repository::factory()->forWorkspace($workspaceA)->create();
    $reviewA = Review::factory()->forRepository($repoA)->create();

    app(WorkspaceContext::class)->bind($workspaceB->id);
    $repoB = Repository::factory()->forWorkspace($workspaceB)->create();
    Review::factory()->forRepository($repoB)->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $results = Review::all();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($reviewA->id);
});

test('each workspace only sees its own review comments', function (): void {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $repoA = Repository::factory()->forWorkspace($workspaceA)->create();
    $reviewA = Review::factory()->forRepository($repoA)->create();
    $commentA = ReviewComment::factory()->forReview($reviewA)->create();

    app(WorkspaceContext::class)->bind($workspaceB->id);
    $repoB = Repository::factory()->forWorkspace($workspaceB)->create();
    $reviewB = Review::factory()->forRepository($repoB)->create();
    ReviewComment::factory()->forReview($reviewB)->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $results = ReviewComment::all();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($commentA->id);
});

test('each workspace only sees its own llm calls', function (): void {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $repoA = Repository::factory()->forWorkspace($workspaceA)->create();
    $reviewA = Review::factory()->forRepository($repoA)->create();
    $callA = ReviewsLlmCall::factory()->forReview($reviewA)->create();

    app(WorkspaceContext::class)->bind($workspaceB->id);
    $repoB = Repository::factory()->forWorkspace($workspaceB)->create();
    $reviewB = Review::factory()->forRepository($repoB)->create();
    ReviewsLlmCall::factory()->forReview($reviewB)->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    $results = ReviewsLlmCall::all();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($callA->id);
});

test('querying workspace-scoped model without bound context throws', function (): void {
    app(WorkspaceContext::class)->clear();

    expect(fn () => Repository::all())->toThrow(WorkspaceContextMissingException::class);
});
