<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateRepositoryRequest;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Scm\Dto\WebhookHandle;
use App\Services\Scm\ScmDriverFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    /**
     * List workspace repositories.
     */
    public function index(Workspace $workspace): Response
    {
        $repositories = $workspace->repositories()->latest()->get();

        return Inertia::render('workspaces/Show', [
            'workspace' => $workspace,
            'repositories' => $repositories,
        ]);
    }

    /**
     * Sync repositories from Bitbucket into the local DB.
     */
    public function sync(Workspace $workspace): RedirectResponse
    {
        $driver = app(ScmDriverFactory::class)->make($workspace);
        $remoteRepos = $driver->listRepositories();

        foreach ($remoteRepos as $remote) {
            $workspace->repositories()->updateOrCreate(
                ['scm_repo_id' => $remote->scmRepoId],
                [
                    'name' => $remote->name,
                    'full_name' => $remote->fullName,
                    'default_branch' => $remote->defaultBranch,
                    'last_synced_at' => now(),
                ],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repositories synced.')]);

        return to_route('workspaces.show', $workspace->slug);
    }

    /**
     * Enable or disable a repository, managing webhook lifecycle automatically.
     */
    public function update(UpdateRepositoryRequest $request, Workspace $workspace, Repository $repository): RedirectResponse
    {
        $enabling = (bool) $request->validated('enabled');

        try {
            if ($enabling && ! $repository->enabled) {
                $this->enableRepository($workspace, $repository);
            } elseif (! $enabling && $repository->enabled) {
                $this->disableRepository($workspace, $repository);
            }
        } catch (\RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('workspaces.show', $workspace->slug);
        }

        return to_route('workspaces.show', $workspace->slug);
    }

    private function enableRepository(Workspace $workspace, Repository $repository): void
    {
        if (empty($workspace->webhook_secret)) {
            $workspace->update(['webhook_secret' => Str::random(40)]);
        }

        $webhookToken = Str::random(40);

        $driver = app(ScmDriverFactory::class)->make($workspace);

        $webhookUrl = route('bitbucket.webhook', [$repository->id, $webhookToken]);

        $handle = $driver->registerWebhook(
            $repository->scm_repo_id,
            $webhookUrl,
            $workspace->webhook_secret,
        );

        $repository->update([
            'enabled' => true,
            'webhook_token' => $webhookToken,
            'scm_webhook_uuid' => $handle?->scmWebhookUuid,
        ]);
    }

    private function disableRepository(Workspace $workspace, Repository $repository): void
    {
        if ($repository->scm_webhook_uuid) {
            $driver = app(ScmDriverFactory::class)->make($workspace);
            $driver->deleteWebhook(
                $repository->scm_repo_id,
                new WebhookHandle(scmWebhookUuid: $repository->scm_webhook_uuid),
            );
        }

        $repository->update([
            'enabled' => false,
            'scm_webhook_uuid' => null,
            'webhook_token' => null,
        ]);
    }
}
