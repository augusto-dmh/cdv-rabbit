<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Workspace;

interface CostReservationInterface
{
    public function reserve(int $workspaceId, int $tokens, int $dailyCap): ReservationResult;

    public function consumed(int $workspaceId): int;

    public function release(int $workspaceId, int $tokens): void;

    public function dailyCapFor(Workspace $workspace): int;

    public function notifyIfThresholdExceeded(Workspace $workspace, int $consumed): void;
}
