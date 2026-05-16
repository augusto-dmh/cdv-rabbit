<?php

declare(strict_types=1);

namespace App\Services\Scm\Github;

use App\Enums\WorkspaceHealth;
use App\Models\Repository;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * Reacts to the GitHub App `installation.deleted` webhook event: clears the
 * per-Workspace installation_id, marks the workspace unhealthy, and disables
 * every repository it owned. Idempotent — a repeated delivery for an
 * installation that's already cleared is a no-op.
 */
class GithubInstallationManager
{
    public function __construct(
        private readonly InstallationTokenCache $tokenCache,
    ) {}

    public function handleUninstall(string $installationId): void
    {
        if ($installationId === '') {
            return;
        }

        $workspace = Workspace::withoutGlobalScope('workspace')
            ->where('github_installation_id', $installationId)
            ->first();

        if ($workspace === null) {
            // Idempotent: already cleared or never mapped.
            $this->tokenCache->forget($installationId);

            return;
        }

        $workspace->update([
            'github_installation_id' => null,
            'health' => WorkspaceHealth::Unhealthy,
        ]);

        Repository::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->update(['enabled' => false]);

        $this->tokenCache->forget($installationId);

        Log::channel('bitbucket')->info('github.installation.deleted', [
            'workspace_id' => $workspace->id,
            'installation_id' => $installationId,
        ]);
    }
}
