<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Concerns\WorkspaceContext;
use App\Queue\BindWorkspaceMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReviewPullRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $repositoryId,
        public readonly int $pullRequestNumber,
        public readonly string $headSha,
    ) {
        $this->onQueue('reviews');
    }

    public function handle(): void
    {
        // W3: fetch diff, call Claude, post comments
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new BindWorkspaceMiddleware(app(WorkspaceContext::class))];
    }
}
