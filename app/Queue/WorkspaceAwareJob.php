<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Contract for jobs that operate within a workspace context.
 *
 * Every class implementing this interface must expose a public readonly
 * int $workspaceId property. BindWorkspaceMiddleware reads this property
 * to bind the WorkspaceContext before handle() runs.
 *
 * Usage in a job:
 *
 *   public function middleware(): array
 *   {
 *       return [app(BindWorkspaceMiddleware::class)];
 *   }
 */
interface WorkspaceAwareJob
{
    public function workspaceId(): int;
}
