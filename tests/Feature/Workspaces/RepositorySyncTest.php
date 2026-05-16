<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
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

    Http::preventStrayRequests();
});

test('sync upserts repositories from bitbucket', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace*' => Http::response([
            'values' => [
                [
                    'uuid' => '{uuid-1}',
                    'name' => 'repo-one',
                    'full_name' => 'my-workspace/repo-one',
                    'mainbranch' => ['name' => 'main'],
                ],
                [
                    'uuid' => '{uuid-2}',
                    'name' => 'repo-two',
                    'full_name' => 'my-workspace/repo-two',
                    'mainbranch' => ['name' => 'develop'],
                ],
                [
                    'uuid' => '{uuid-3}',
                    'name' => 'repo-three',
                    'full_name' => 'my-workspace/repo-three',
                    'mainbranch' => null,
                ],
            ],
            'next' => null,
        ], 200),
    ]);

    $this->actingAs($this->admin)
        ->post(route('workspaces.repositories.sync', $this->workspace->slug))
        ->assertRedirect(route('workspaces.show', $this->workspace->slug));

    app(WorkspaceContext::class)->bind($this->workspace->id);
    $repos = $this->workspace->repositories()->orderBy('name')->get();

    expect($repos)->toHaveCount(3);
    expect($repos[0]->name)->toBe('repo-one');
    expect($repos[0]->full_name)->toBe('my-workspace/repo-one');
    expect($repos[0]->default_branch)->toBe('main');
    expect($repos[0]->last_synced_at)->not->toBeNull();
    expect($repos[1]->name)->toBe('repo-three');
    expect($repos[1]->default_branch)->toBe('main'); // fallback when mainbranch is null
    expect($repos[2]->name)->toBe('repo-two');
    expect($repos[2]->default_branch)->toBe('develop');
});

test('sync updates existing repository record by scm_repo_id', function (): void {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/my-workspace*' => Http::response([
            'values' => [
                [
                    'uuid' => '{uuid-existing}',
                    'name' => 'renamed-repo',
                    'full_name' => 'my-workspace/renamed-repo',
                    'mainbranch' => ['name' => 'main'],
                ],
            ],
            'next' => null,
        ], 200),
    ]);

    $existing = $this->workspace->repositories()->create([
        'scm_repo_id' => '{uuid-existing}',
        'name' => 'old-name',
        'full_name' => 'my-workspace/old-name',
        'default_branch' => 'main',
        'enabled' => false,
        'last_synced_at' => null,
    ]);

    $this->actingAs($this->admin)
        ->post(route('workspaces.repositories.sync', $this->workspace->slug));

    app(WorkspaceContext::class)->bind($this->workspace->id);
    expect($this->workspace->repositories()->count())->toBe(1);
    expect($existing->refresh()->name)->toBe('renamed-repo');
});

test('sync returns 403 for non-member', function (): void {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->post(route('workspaces.repositories.sync', $this->workspace->slug))
        ->assertForbidden();
});
