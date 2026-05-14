<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Mail\DailyTokenSpendApproaching;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

final class CostReservation implements CostReservationInterface
{
    private const TTL_SECONDS = 93600; // 26 hours — covers timezone slack

    private string $luaScript;

    public function __construct()
    {
        $this->luaScript = (string) file_get_contents(
            base_path('resources/lua/cost_reservation_check_incr.lua')
        );
    }

    /**
     * Atomically reserve tokens against the workspace's daily cap.
     * Uses a Lua script so check + increment is a single Redis transaction — no TOCTOU.
     */
    public function reserve(int $workspaceId, int $tokens, int $dailyCap): ReservationResult
    {
        $key = $this->bucketKey($workspaceId);

        /** @var array{int, int} $result */
        $result = Redis::eval(
            $this->luaScript,
            1,
            $key,
            (string) $tokens,
            (string) $dailyCap,
            (string) self::TTL_SECONDS,
        );

        $granted = (int) $result[0] === 1;
        $current = (int) $result[1];

        return new ReservationResult(granted: $granted, consumed: $current, cap: $dailyCap);
    }

    /**
     * Read the current consumed token count (non-atomic, best-effort).
     */
    public function consumed(int $workspaceId): int
    {
        return (int) Redis::get($this->bucketKey($workspaceId));
    }

    /**
     * Refund tokens after a failed job (bounded at 0 — never go negative).
     */
    public function release(int $workspaceId, int $tokens): void
    {
        $key = $this->bucketKey($workspaceId);
        $current = (int) Redis::get($key);

        if ($current <= 0) {
            return;
        }

        $decrement = min($tokens, $current);
        Redis::decrby($key, $decrement);
    }

    /**
     * Return the daily token cap for a workspace.
     */
    public function dailyCapFor(Workspace $workspace): int
    {
        return (int) ($workspace->daily_token_cap ?? 200_000);
    }

    /**
     * Dispatch a cost-approaching alert if the threshold is exceeded.
     * Called by the job after a successful reservation — fire-and-forget.
     */
    public function notifyIfThresholdExceeded(Workspace $workspace, int $consumed): void
    {
        $cap = $this->dailyCapFor($workspace);
        $threshold = (int) ($workspace->daily_token_cap_alert_threshold ?? 70);

        if ($cap <= 0 || $consumed < (int) ceil($cap * $threshold / 100)) {
            return;
        }

        $admins = $workspace->users()->wherePivot('role', 'admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new DailyTokenSpendApproaching($workspace, $consumed, $cap));
        }
    }

    private function bucketKey(int $workspaceId): string
    {
        $date = now()->utc()->format('Ymd');

        return "workspace:{$workspaceId}:tokens:{$date}";
    }
}
