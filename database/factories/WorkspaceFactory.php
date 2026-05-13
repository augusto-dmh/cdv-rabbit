<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'owner_id' => User::factory(),
            'bitbucket_workspace_slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'bitbucket_token' => fake()->sha256(),
            'bitbucket_service_account' => fake()->userName(),
            'webhook_secret' => Str::random(40),
            'kill_switch_enabled' => false,
            'health' => 'healthy',
        ];
    }
}
