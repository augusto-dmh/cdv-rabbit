<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateRepositoryRequest;
use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
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
        $client = new BitbucketClient($workspace);
        $remoteRepos = $client->listRepositories();

        foreach ($remoteRepos as $remote) {
            $workspace->repositories()->updateOrCreate(
                ['scm_repo_id' => $remote['uuid']],
                [
                    'name' => $remote['name'],
                    'full_name' => $remote['full_name'],
                    'default_branch' => $remote['mainbranch']['name'] ?? 'main',
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

        $client = new BitbucketClient($workspace);

        $webhookUrl = route('bitbucket.webhook', [$repository->id, $webhookToken]);

        $result = $client->registerWebhook(
            $repository->full_name,
            $webhookUrl,
            $workspace->webhook_secret,
            ['pullrequest:created'],
        );

        $repository->update([
            'enabled' => true,
            'webhook_token' => $webhookToken,
            'scm_webhook_uuid' => $result['uuid'] ?? null,
        ]);
    }

    private function disableRepository(Workspace $workspace, Repository $repository): void
    {
        if ($repository->scm_webhook_uuid) {
            $client = new BitbucketClient($workspace);
            $client->deleteWebhook($repository->full_name, $repository->scm_webhook_uuid);
        }

        $repository->update([
            'enabled' => false,
            'scm_webhook_uuid' => null,
            'webhook_token' => null,
        ]);
    }
}
