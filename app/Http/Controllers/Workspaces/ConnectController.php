<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\ConnectWorkspaceRequest;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectController extends Controller
{
    /**
     * Show the Bitbucket connection form for the workspace.
     */
    public function edit(Workspace $workspace): Response
    {
        return Inertia::render('workspaces/Connect', [
            'workspace' => $workspace->only('id', 'name', 'slug', 'bitbucket_workspace_slug', 'bitbucket_service_account'),
            'isConnected' => filled($workspace->bitbucket_token),
        ]);
    }

    /**
     * Validate token against BB API and persist if valid.
     */
    public function update(ConnectWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validated();

        $workspace->bitbucket_token = $validated['bitbucket_token'];
        $workspace->bitbucket_workspace_slug = $validated['bitbucket_workspace_slug'];

        $client = new BitbucketClient($workspace);

        $me = $client->me();

        if (empty($me['account_id'])) {
            return back()->withErrors([
                'bitbucket_token' => __('Token is invalid or missing required Bitbucket scopes.'),
            ]);
        }

        $workspace->bitbucket_service_account = $validated['bitbucket_service_account'];
        $workspace->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bitbucket workspace connected.')]);

        return to_route('workspaces.connect.edit', $workspace->slug);
    }

    /**
     * Revoke the stored Bitbucket token (user must re-enter).
     */
    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->where('role', 'admin')->exists()) {
            abort(403);
        }

        $workspace->update([
            'bitbucket_token' => null,
            'bitbucket_service_account' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bitbucket token revoked.')]);

        return to_route('workspaces.connect.edit', $workspace->slug);
    }
}
