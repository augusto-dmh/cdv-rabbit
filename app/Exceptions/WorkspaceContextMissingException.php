<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class WorkspaceContextMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'WorkspaceContext is unbound. Every query touching a workspace-scoped model must bind WorkspaceContext via BindWorkspaceMiddleware (jobs) or per-request middleware (HTTP). This is a tenant-isolation guard — never make it lenient.'
        );
    }
}
