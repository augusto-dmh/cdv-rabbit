<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\ReviewsLlmCall;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cross-workspace LGPD retention command.
 * Uses BelongsToWorkspace::withoutWorkspaceScope() — intentional breach documented here:
 * this is a system-level maintenance command that must operate across all tenants.
 */
class PurgeStaleReviews extends Command
{
    protected $signature = 'rabbit:purge-stale {--dry-run : Print what would be deleted without making changes}';

    protected $description = 'Soft-delete reviews >365d, hard-delete soft-deleted >30d, hard-delete webhook_deliveries >90d. Runs cross-workspace.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $runId = Str::uuid()->toString();
        $softDeleteDays = (int) config('cdv-rabbit.retention.soft_delete_days', 365);
        $hardDeleteGraceDays = (int) config('cdv-rabbit.retention.hard_delete_grace_days', 30);

        // Step 1: Soft-delete reviews older than soft_delete_days
        $softDeleteCutoff = now()->subDays($softDeleteDays);
        $softDeleteQuery = Review::withoutWorkspaceScope()
            ->whereNull('deleted_at')
            ->where('created_at', '<', $softDeleteCutoff);

        $softDeleteCount = $softDeleteQuery->count();

        if ($dryRun) {
            $this->line("[dry-run] Would soft-delete {$softDeleteCount} reviews older than {$softDeleteDays} days.");
        } else {
            $softDeleteQuery->get()->each(function (Review $review): void {
                ReviewComment::withoutWorkspaceScope()
                    ->where('review_id', $review->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                ReviewsLlmCall::withoutWorkspaceScope()
                    ->where('review_id', $review->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                $review->delete();
            });
        }

        Log::info('rabbit:purge-stale soft-delete phase', [
            'run_id' => $runId,
            'kind' => 'soft_delete',
            'count' => $softDeleteCount,
            'dry_run' => $dryRun,
            'cutoff_days' => $softDeleteDays,
        ]);

        // Step 2: Hard-delete reviews soft-deleted longer than hard_delete_grace_days
        $hardDeleteCutoff = now()->subDays($hardDeleteGraceDays);
        $hardDeleteQuery = Review::withoutWorkspaceScope()
            ->onlyTrashed()
            ->where('deleted_at', '<', $hardDeleteCutoff);

        $hardDeleteCount = $hardDeleteQuery->count();

        if ($dryRun) {
            $this->line("[dry-run] Would hard-delete {$hardDeleteCount} reviews soft-deleted longer than {$hardDeleteGraceDays} days.");
        } else {
            $hardDeleteQuery->get()->each(function (Review $review): void {
                ReviewComment::withoutWorkspaceScope()
                    ->where('review_id', $review->id)
                    ->forceDelete();

                ReviewsLlmCall::withoutWorkspaceScope()
                    ->where('review_id', $review->id)
                    ->forceDelete();

                $review->forceDelete();
            });
        }

        Log::info('rabbit:purge-stale hard-delete phase', [
            'run_id' => $runId,
            'kind' => 'hard_delete',
            'count' => $hardDeleteCount,
            'dry_run' => $dryRun,
            'cutoff_days' => $hardDeleteGraceDays,
        ]);

        // Step 3: Hard-delete webhook_deliveries older than 90 days (no soft-delete)
        $webhookCutoff = now()->subDays(90);
        $webhookQuery = WebhookDelivery::query()->where('created_at', '<', $webhookCutoff);
        $webhookCount = $webhookQuery->count();

        if ($dryRun) {
            $this->line("[dry-run] Would hard-delete {$webhookCount} webhook_deliveries older than 90 days.");
        } else {
            $webhookQuery->delete();
        }

        Log::info('rabbit:purge-stale webhook phase', [
            'run_id' => $runId,
            'kind' => 'webhook_deliveries',
            'count' => $webhookCount,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('Dry run complete. No changes made.');
        } else {
            $this->info("Purge complete. Soft-deleted: {$softDeleteCount}, hard-deleted: {$hardDeleteCount}, webhooks: {$webhookCount}.");
        }

        return self::SUCCESS;
    }
}
