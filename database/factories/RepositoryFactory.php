<?php

namespace Database\Factories;

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->slug(2);

        return [
            'workspace_id' => Workspace::factory(),
            'bitbucket_uuid' => fake()->uuid(),
            'name' => $name,
            'full_slug' => fake()->userName().'/'.$name,
            'webhook_uuid' => fake()->uuid(),
            'webhook_token' => Str::random(40),
            'default_branch' => 'main',
            'last_synced_at' => null,
            'enabled' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Repository $repository): void {
            app(WorkspaceContext::class)->bind($repository->workspace_id);
        })->afterCreating(function (Repository $repository): void {
            app(WorkspaceContext::class)->bind($repository->workspace_id);
        });
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(['workspace_id' => $workspace->id])
            ->afterMaking(function (Repository $repository) use ($workspace): void {
                app(WorkspaceContext::class)->bind($workspace->id);
            })
            ->afterCreating(function (Repository $repository) use ($workspace): void {
                app(WorkspaceContext::class)->bind($workspace->id);
            });
    }
}
