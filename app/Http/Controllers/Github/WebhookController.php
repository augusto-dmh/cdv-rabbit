<?php

declare(strict_types=1);

namespace App\Http\Controllers\Github;

use App\Concerns\WorkspaceContext;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\Scm\Github\GithubInstallationManager;
use App\Services\Webhook\WebhookIngestionPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookIngestionPipeline $pipeline,
        GithubInstallationManager $installations,
    ): JsonResponse {
        // 1) HMAC-SHA256 against the per-App webhook secret (ADR 0003).
        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            return response()->json(['error' => 'Missing signature'], 401);
        }

        $secret = (string) config('services.github.app_webhook_secret', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2) Event dispatch.
        $event = (string) $request->header('X-GitHub-Event', '');
        $action = (string) ($request->json('action') ?? '');
        $deliveryId = (string) $request->header('X-GitHub-Delivery', '');

        if ($event === 'installation' && $action === 'deleted') {
            $installationId = (string) ($request->json('installation.id') ?? '');
            $installations->handleUninstall($installationId);

            return response()->json(['message' => 'installation deleted'], 202);
        }

        if ($event !== 'pull_request' || $action !== 'opened') {
            return response()->json(['message' => 'event ignored'], 202);
        }

        // 3) Resolve repository by GH numeric id stored as scm_repo_id.
        $scmRepoId = (string) ($request->json('repository.id') ?? '');
        $repository = Repository::withoutWorkspaceScope()
            ->where('scm_repo_id', $scmRepoId)
            ->first();

        if ($repository === null || ! $repository->enabled) {
            return response()->json(['message' => 'unknown or disabled repo'], 202);
        }

        app(WorkspaceContext::class)->bind($repository->workspace_id);

        // 4) Dispatch through shared pipeline.
        $prNumber = (int) ($request->json('pull_request.number') ?? 0);
        $headSha = (string) ($request->json('pull_request.head.sha') ?? '');

        $delivery = $pipeline->ingestPullRequestCreated(
            $repository,
            $deliveryId,
            'github_cloud',
            $prNumber,
            $headSha,
            'pull_request.opened',
        );

        if ($delivery === null) {
            return response()->json(['message' => 'duplicate'], 200);
        }

        return response()->json(['delivery_id' => $delivery->id], 202);
    }
}
