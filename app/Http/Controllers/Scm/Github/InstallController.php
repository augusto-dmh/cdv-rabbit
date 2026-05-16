<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scm\Github;

use App\Enums\WorkspaceHealth;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Scm\Github\StateTokenSigner;
use App\Services\Scm\ScmDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstallController extends Controller
{
    /**
     * AC29 — Build a signed state token and redirect the admin's browser to the
     * GitHub App install URL. Only Workspace admins may initiate the flow.
     */
    public function start(Request $request, Workspace $workspace, StateTokenSigner $signer): RedirectResponse
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->where('role', 'admin')->exists()) {
            abort(403);
        }

        $token = $signer->sign($workspace->id);
        $slug = (string) config('services.github.app_slug', '');

        return redirect()->away("https://github.com/apps/{$slug}/installations/new?state={$token}");
    }

    /**
     * AC30 / AC31 / AC32 — Validate the state token, enforce 1:1 installation
     * binding, persist installation_id, run verifyCredentials, and route the
     * admin back to the workspace.
     */
    public function callback(Request $request, StateTokenSigner $signer, ScmDriverFactory $scmFactory): Response
    {
        $token = (string) $request->query('state', '');
        $payload = $signer->verify($token);

        // AC31: invalid / expired / replayed state.
        if ($payload === null) {
            return response()->json(['error' => 'Invalid or expired state token.'], 403);
        }

        $installationId = (string) $request->query('installation_id', '');

        if ($installationId === '') {
            return response()->json(['error' => 'Missing installation_id.'], 400);
        }

        // AC32: enforce 1:1 — refuse to overwrite another workspace's binding.
        $existing = Workspace::withoutGlobalScope('workspace')
            ->where('github_installation_id', $installationId)
            ->first();

        if ($existing !== null && $existing->id !== $payload['w']) {
            return response()->json([
                'error' => 'This GitHub installation is already linked to another workspace.',
                'existing_workspace_slug' => $existing->slug,
            ], 409);
        }

        $workspace = Workspace::withoutGlobalScope('workspace')->find($payload['w']);

        if ($workspace === null) {
            return response()->json(['error' => 'Workspace not found.'], 404);
        }

        $workspace->update([
            'github_installation_id' => $installationId,
            'health' => WorkspaceHealth::Healthy,
        ]);

        // AC30 — verifyCredentials right after the install. If it fails the workspace stays
        // mapped but is marked unhealthy so the admin can troubleshoot.
        $check = $scmFactory->make($workspace->fresh())->verifyCredentials();

        if (! $check->valid) {
            $workspace->update(['health' => WorkspaceHealth::Unhealthy]);
        }

        return $this->redirectToWorkspace($workspace->slug);
    }

    private function redirectToWorkspace(string $slug): RedirectResponse|JsonResponse
    {
        // In production this is a browser-side flow; the admin lands on the workspace page.
        return redirect()->route('workspaces.show', $slug);
    }
}
