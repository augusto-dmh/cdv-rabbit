<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Paused = 'paused';
}
