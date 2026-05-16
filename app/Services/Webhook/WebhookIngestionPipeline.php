<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Shared post-validation pipeline used by both Bitbucket and GitHub webhook
 * controllers. Encapsulates the transactional insert + job dispatch + status
 * update, plus idempotency enforcement via the unique index on
 * webhook_deliveries.scm_delivery_id.
 *
 * Returns the WebhookDelivery on success; null when the scm_delivery_id is a
 * duplicate (caller responds 200 "duplicate").
 */
class WebhookIngestionPipeline
{
    public function ingestPullRequestCreated(
        Repository $repository,
        string $scmDeliveryId,
        string $scmProvider,
        int $prNumber,
        string $headSha,
        string $eventType,
    ): ?WebhookDelivery {
        return DB::transaction(function () use ($repository, $scmDeliveryId, $scmProvider, $prNumber, $headSha, $eventType): ?WebhookDelivery {
            if (WebhookDelivery::where('scm_delivery_id', $scmDeliveryId)->exists()) {
                return null;
            }

            $delivery = WebhookDelivery::create([
                'scm_delivery_id' => $scmDeliveryId,
                'scm_provider' => $scmProvider,
                'repository_id' => $repository->id,
                'event_type' => $eventType,
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
    }
}
