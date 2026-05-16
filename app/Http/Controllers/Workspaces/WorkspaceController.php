<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Concerns\WorkspaceContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\CreateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * List workspaces the authenticated user belongs to.
     */
    public function index(Request $request): Response
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('owner')
            ->latest()
            ->get();

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Show a single workspace with its repositories and recent reviews.
     */
    public function show(Request $request, Workspace $workspace): Response
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        $context = app(WorkspaceContext::class);
        $context->bind($workspace->id);

        try {
            $repositories = $workspace->repositories()
                ->latest()
                ->get();
        } finally {
            $context->clear();
        }

        $isAdmin = $workspace->users()
            ->where('user_id', $request->user()->id)
            ->where('role', 'admin')
            ->exists();

        return Inertia::render('workspaces/Show', [
            'workspace' => $workspace,
            'repositories' => $repositories,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Create a new workspace and auto-add owner as admin.
     */
    public function store(CreateWorkspaceRequest $request): RedirectResponse
    {
        $workspace = Workspace::create([
            ...$request->validated(),
            'owner_id' => $request->user()->id,
        ]);

        $workspace->users()->attach($request->user()->id, ['role' => 'admin']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('workspaces.show', $workspace->slug);
    }

    /**
     * Update workspace name.
     */
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->where('role', 'admin')->exists()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'llm_provider' => ['sometimes', 'in:anthropic,openai'],
            // AC28: scm_provider is immutable after Workspace creation. Rejected with 422 when sent.
            'scm_provider' => ['prohibited'],
        ]);

        $workspace->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace updated.')]);

        return to_route('workspaces.show', $workspace->slug);
    }
}
