<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Workspace;
use App\Services\Review\CostReservationInterface;
use App\Services\Review\ReservationResult;

/**
 * Test double for CostReservation — avoids Redis and Lua in feature tests.
 * Implements CostReservationInterface so it satisfies the job's type hint.
 */
class FakeCostReservation implements CostReservationInterface
{
    private bool $granted;

    private int $cap;

    private int $consumed = 0;

    public bool $releaseCalled = false;

    public bool $notifyCalled = false;

    public function __construct(bool $granted = true, int $cap = 200_000)
    {
        $this->granted = $granted;
        $this->cap = $cap;
        $this->consumed = $granted ? 10_000 : $cap;
    }

    public function reserve(int $workspaceId, string $provider, int $tokens, int $dailyCap): ReservationResult
    {
        return new ReservationResult(
            granted: $this->granted,
            consumed: $this->consumed,
            cap: $dailyCap,
        );
    }

    public function consumed(int $workspaceId, string $provider): int
    {
        return $this->consumed;
    }

    public function release(int $workspaceId, string $provider, int $tokens): void
    {
        $this->releaseCalled = true;
    }

    public function dailyCapFor(Workspace $workspace): int
    {
        return $this->cap;
    }

    public function notifyIfThresholdExceeded(Workspace $workspace, int $consumed): void
    {
        $this->notifyCalled = true;
    }
}
