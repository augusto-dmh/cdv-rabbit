<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Concerns\WorkspaceContext;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMember
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Workspace $workspace */
        $workspace = $request->route('workspace');

        if (! $workspace->users()->where('user_id', Auth::id())->exists()) {
            abort(403);
        }

        $this->context->bind($workspace->id);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
