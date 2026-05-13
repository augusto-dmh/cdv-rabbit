<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bitbucket;

use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, Repository $repository, string $webhookToken): JsonResponse
    {
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

        $hookUuid = $request->header('X-Hook-UUID', '');

        if (WebhookDelivery::where('bitbucket_uuid', $hookUuid)->exists()) {
            return response()->json(['message' => 'duplicate'], 200);
        }

        $payload = $request->json()->all();
        $prNumber = $payload['pullrequest']['id'] ?? 0;
        $headSha = $payload['pullrequest']['source']['commit']['hash'] ?? '';

        $delivery = DB::transaction(function () use ($repository, $hookUuid, $eventKey, $prNumber, $headSha): WebhookDelivery {
            $delivery = WebhookDelivery::create([
                'bitbucket_uuid' => $hookUuid,
                'repository_id' => $repository->id,
                'event_type' => $eventKey,
                'status' => WebhookDeliveryStatus::Received,
                'created_at' => now(),
            ]);

            ReviewPullRequestJob::dispatch(
                $repository->workspace_id,
                $repository->id,
                $prNumber,
                $headSha,
            );

            $delivery->update([
                'status' => WebhookDeliveryStatus::Dispatched,
                'processed_at' => now(),
            ]);

            return $delivery;
        });

        return response()->json(['delivery_id' => $delivery->id], 202);
    }
}
