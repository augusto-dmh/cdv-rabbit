<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('AC27: POST /workspaces requires scm_provider', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('workspaces.store'), [
        'name' => 'My WS',
        'slug' => 'my-ws-'.uniqid(),
    ]);

    $response->assertSessionHasErrors(['scm_provider']);
});

test('AC27: POST /workspaces rejects unknown scm_provider value', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('workspaces.store'), [
        'name' => 'My WS',
        'slug' => 'my-ws-'.uniqid(),
        'scm_provider' => 'gitlab_cloud',
    ]);

    $response->assertSessionHasErrors(['scm_provider']);
});

test('AC27: POST /workspaces persists github_cloud workspace', function (): void {
    $user = User::factory()->create();
    $slug = 'my-ws-'.uniqid();

    $this->actingAs($user)->post(route('workspaces.store'), [
        'name' => 'My WS',
        'slug' => $slug,
        'scm_provider' => 'github_cloud',
    ])->assertRedirect();

    $workspace = Workspace::where('slug', $slug)->first();
    expect($workspace)->not->toBeNull();
    expect($workspace->scm_provider->value)->toBe('github_cloud');
});

test('AC28: PATCH /workspaces/{slug} rejects scm_provider field with 422', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['scm_provider' => 'bitbucket_cloud']);
    $workspace->users()->attach($user->id, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->patch(route('workspaces.update', $workspace->slug), [
            'scm_provider' => 'github_cloud',
        ]);

    $response->assertSessionHasErrors(['scm_provider']);

    $workspace->refresh();
    expect($workspace->scm_provider->value)->toBe('bitbucket_cloud');
});
