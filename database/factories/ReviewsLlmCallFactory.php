<?php

namespace Database\Factories;

use App\Concerns\WorkspaceContext;
use App\Enums\LlmCallRole;
use App\Models\Review;
use App\Models\ReviewsLlmCall;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewsLlmCall>
 */
class ReviewsLlmCallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'workspace_id' => Workspace::factory(),
            'model_id' => 'claude-sonnet-4-6',
            'role' => LlmCallRole::Review,
            'input_tokens' => fake()->numberBetween(500, 5000),
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'output_tokens' => fake()->numberBetween(100, 1000),
            'request_id' => null,
            'ratelimit_tokens_remaining' => null,
            'ratelimit_tokens_reset' => null,
            'duration_ms' => fake()->numberBetween(500, 10000),
            'http_status' => 200,
            'error_type' => null,
            'created_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ReviewsLlmCall $call): void {
            app(WorkspaceContext::class)->bind($call->workspace_id);
        })->afterCreating(function (ReviewsLlmCall $call): void {
            app(WorkspaceContext::class)->bind($call->workspace_id);
        });
    }

    public function forReview(Review $review): static
    {
        return $this->state([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
        ])->afterMaking(function (ReviewsLlmCall $call) use ($review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        })->afterCreating(function (ReviewsLlmCall $call) use ($review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        });
    }
}
