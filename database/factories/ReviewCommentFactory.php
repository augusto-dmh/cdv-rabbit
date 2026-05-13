<?php

namespace Database\Factories;

use App\Concerns\WorkspaceContext;
use App\Enums\CommentType;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewComment>
 */
class ReviewCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'workspace_id' => Workspace::factory(),
            'file_path' => fake()->filePath(),
            'line' => fake()->numberBetween(1, 500),
            'bitbucket_comment_id' => null,
            'posted_at' => null,
            'comment_type' => CommentType::Inline,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ReviewComment $comment): void {
            app(WorkspaceContext::class)->bind($comment->workspace_id);
        })->afterCreating(function (ReviewComment $comment): void {
            app(WorkspaceContext::class)->bind($comment->workspace_id);
        });
    }

    public function forReview(Review $review): static
    {
        return $this->state([
            'review_id' => $review->id,
            'workspace_id' => $review->workspace_id,
        ])->afterMaking(function (ReviewComment $comment) use ($review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        })->afterCreating(function (ReviewComment $comment) use ($review): void {
            app(WorkspaceContext::class)->bind($review->workspace_id);
        });
    }
}
