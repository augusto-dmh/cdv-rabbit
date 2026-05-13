<?php

declare(strict_types=1);

namespace App\Queue;

use App\Concerns\WorkspaceContext;
use Closure;

/**
 * Job middleware that binds WorkspaceContext before handle() runs and
 * clears it afterward — even if an exception is thrown.
 *
 * Every workspace-aware job must list this middleware in middleware():
 *
 *   public function middleware(): array
 *   {
 *       return [app(BindWorkspaceMiddleware::class)];
 *   }
 *
 * The job MUST expose a public int $workspaceId property. The middleware
 * throws \InvalidArgumentException if that property is absent or null.
 */
class BindWorkspaceMiddleware
{
    public function __construct(
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(mixed $job, Closure $next): void
    {
        if (! property_exists($job, 'workspaceId') || $job->workspaceId === null) {
            throw new \InvalidArgumentException(
                sprintf('Job %s must expose a non-null $workspaceId property to use BindWorkspaceMiddleware.', $job::class),
            );
        }

        $this->context->bind($job->workspaceId);

        try {
            $next($job);
        } finally {
            $this->context->clear();
        }
    }
}
