<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

// ---------------------------------------------------------------------------
// AC27: workspace llm_provider can be switched via PATCH
// ---------------------------------------------------------------------------

it('AC27: admin can switch llm_provider to openai', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['llm_provider' => 'anthropic']);
    $workspace->users()->attach($user, ['role' => 'admin']);

    $this->actingAs($user)
        ->patch(route('workspaces.update', $workspace->slug), ['llm_provider' => 'openai'])
        ->assertRedirect();

    expect($workspace->fresh()->llm_provider)->toBe('openai');
});

it('AC27: admin can switch llm_provider back to anthropic', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['llm_provider' => 'openai']);
    $workspace->users()->attach($user, ['role' => 'admin']);

    $this->actingAs($user)
        ->patch(route('workspaces.update', $workspace->slug), ['llm_provider' => 'anthropic'])
        ->assertRedirect();

    expect($workspace->fresh()->llm_provider)->toBe('anthropic');
});

it('rejects invalid llm_provider value', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['llm_provider' => 'anthropic']);
    $workspace->users()->attach($user, ['role' => 'admin']);

    $this->actingAs($user)
        ->patch(route('workspaces.update', $workspace->slug), ['llm_provider' => 'gemini'])
        ->assertSessionHasErrors('llm_provider');

    expect($workspace->fresh()->llm_provider)->toBe('anthropic');
});
