<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bitbucket;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\Webhook\WebhookIngestionPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Repository $repository,
        string $webhookToken,
        WebhookIngestionPipeline $pipeline,
    ): JsonResponse {
        if (! hash_equals($repository->webhook_token, $webhookToken)) {
            abort(404);
        }

        $signature = $request->header('X-Hub-Signature');

        if (! $signature) {
            return response()->json(['error' => 'Missing signature'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $repository->workspace->webhook_secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $eventKey = $request->header('X-Event-Key');

        if ($eventKey !== 'pullrequest:created') {
            return response()->json(['message' => 'event ignored'], 202);
        }

        $hookUuid = (string) $request->header('X-Hook-UUID', '');

        $payload = $request->json()->all();
        $prNumber = (int) ($payload['pullrequest']['id'] ?? 0);
        $headSha = (string) ($payload['pullrequest']['source']['commit']['hash'] ?? '');

        $delivery = $pipeline->ingestPullRequestCreated(
            $repository,
            $hookUuid,
            'bitbucket_cloud',
            $prNumber,
            $headSha,
            $eventKey,
        );

        if ($delivery === null) {
            return response()->json(['message' => 'duplicate'], 200);
        }

        return response()->json(['delivery_id' => $delivery->id], 202);
    }
}
