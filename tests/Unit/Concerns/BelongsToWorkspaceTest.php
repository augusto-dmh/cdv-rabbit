<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Exceptions\WorkspaceContextMissingException;
use App\Models\Repository;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('creating a workspace-scoped model without bound context throws when workspace_id is absent', function (): void {
    app(WorkspaceContext::class)->clear();

    // The creating event calls current() only when workspace_id is not set.
    // Provide no workspace_id so the trait tries to auto-fill from context.
    expect(fn () => Repository::create([
        'bitbucket_uuid' => 'abc-unique-'.uniqid(),
        'name' => 'test',
        'full_slug' => 'org/test-'.uniqid(),
        'webhook_token' => 'tok',
        'default_branch' => 'main',
    ]))->toThrow(WorkspaceContextMissingException::class);
});

test('workspace_id is auto-filled on create when context is bound', function (): void {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->bind($workspace->id);

    $repo = Repository::factory()->forWorkspace($workspace)->create();

    expect($repo->workspace_id)->toBe($workspace->id);
});

test('withoutWorkspaceScope returns records across all workspaces', function (): void {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    app(WorkspaceContext::class)->bind($workspaceA->id);
    Repository::factory()->forWorkspace($workspaceA)->create();

    app(WorkspaceContext::class)->bind($workspaceB->id);
    Repository::factory()->forWorkspace($workspaceB)->create();

    app(WorkspaceContext::class)->clear();

    $all = Repository::withoutWorkspaceScope()->get();

    expect($all)->toHaveCount(2);
});
