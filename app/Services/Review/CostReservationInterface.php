<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Workspace;

interface CostReservationInterface
{
    public function reserve(int $workspaceId, string $provider, int $tokens, int $dailyCap): ReservationResult;

    public function consumed(int $workspaceId, string $provider): int;

    public function release(int $workspaceId, string $provider, int $tokens): void;

    public function dailyCapFor(Workspace $workspace): int;

    public function notifyIfThresholdExceeded(Workspace $workspace, int $consumed): void;
}
