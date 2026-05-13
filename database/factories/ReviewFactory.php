<?php

namespace Database\Factories;

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'repository_id' => Repository::factory(),
            'pull_request_number' => fake()->numberBetween(1, 9999),
            'head_sha' => fake()->sha1(),
            'base_sha' => fake()->sha1(),
            'status' => ReviewStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
            'summary_comment_id' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_usd_cents' => 0,
            'secrets_redacted' => 0,
            'error_class' => null,
            'error_message' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Review $review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        })->afterCreating(function (Review $review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        });
    }

    public function forRepository(Repository $repository): static
    {
        return $this->state([
            'workspace_id' => $repository->workspace_id,
            'repository_id' => $repository->id,
        ])->afterMaking(function (Review $review) use ($repository): void {
            app(WorkspaceContext::class)->bind($repository->workspace_id);
        })->afterCreating(function (Review $review) use ($repository): void {
            app(WorkspaceContext::class)->bind($repository->workspace_id);
        });
    }
}
