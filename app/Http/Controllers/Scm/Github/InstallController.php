<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scm\Github;

use App\Enums\WorkspaceHealth;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Scm\ScmDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class InstallController extends Controller
{
    /**
     * How long the user has between clicking "Install on GitHub" and arriving
     * back at our Setup URL. Generous enough for a real install flow,
     * tight enough to bound CSRF replay risk.
     */
    private const INSTALL_SESSION_TTL_SECONDS = 600;

    /**
     * Session key holding `{workspace_id, expires_at}` while the user is on
     * the GitHub install page. Consumed (single-use) by callback().
     */
    private const SESSION_KEY = 'scm_github_install';

    /**
     * Render the GitHub install landing page for a workspace.
     */
    public function show(Request $request, Workspace $workspace): InertiaResponse
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        return Inertia::render('workspaces/ConnectGithub', [
            'workspace' => $workspace->only('id', 'name', 'slug', 'github_installation_id'),
        ]);
    }

    /**
     * AC29 — Stash the workspace id in the session and redirect the admin's
     * browser to the GitHub App install page. After install, GitHub will hit
     * the App's configured Setup URL (which points back to callback() below),
     * carrying only `installation_id` + `setup_action` — there is no custom
     * `state` query param in the Setup URL flow, so the session is how we
     * know which workspace the admin meant to connect.
     *
     * Uses Inertia::location() because the click originates from an Inertia
     * <Link method="post">: a plain redirect()->away() would be followed by
     * Inertia's XHR layer and blocked by CORS on github.com.
     */
    public function start(Request $request, Workspace $workspace): Response
    {
        if (! $workspace->users()->where('user_id', $request->user()->id)->where('role', 'admin')->exists()) {
            abort(403);
        }

        $request->session()->put(self::SESSION_KEY, [
            'workspace_id' => $workspace->id,
            'expires_at' => time() + self::INSTALL_SESSION_TTL_SECONDS,
        ]);

        $slug = (string) config('services.github.app_slug', '');

        return Inertia::location("https://github.com/apps/{$slug}/installations/new");
    }

    /**
     * AC30 / AC31 / AC32 — Configured as the GitHub App's Setup URL. Reads
     * the workspace marker from the session (single-use), enforces 1:1
     * binding, persists installation_id, runs verifyCredentials, and routes
     * the admin back to the workspace.
     */
    public function callback(Request $request, ScmDriverFactory $scmFactory): Response
    {
        // Pull = read-and-remove, so a stale browser tab can't replay.
        $marker = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($marker) || ! isset($marker['workspace_id'], $marker['expires_at'])) {
            return response()->json([
                'error' => 'No active GitHub install session. Start the install from the workspace inside cdv-rabbit first.',
            ], 403);
        }

        if ((int) $marker['expires_at'] < time()) {
            return response()->json([
                'error' => 'GitHub install session expired (10 minutes). Restart from the workspace.',
            ], 403);
        }

        $installationId = (string) $request->query('installation_id', '');

        if ($installationId === '') {
            return response()->json(['error' => 'Missing installation_id.'], 400);
        }

        // AC32: enforce 1:1 — refuse to overwrite another workspace's binding.
        $existing = Workspace::withoutGlobalScope('workspace')
            ->where('github_installation_id', $installationId)
            ->first();

        if ($existing !== null && $existing->id !== (int) $marker['workspace_id']) {
            return response()->json([
                'error' => 'This GitHub installation is already linked to another workspace.',
                'existing_workspace_slug' => $existing->slug,
            ], 409);
        }

        $workspace = Workspace::withoutGlobalScope('workspace')->find((int) $marker['workspace_id']);

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
