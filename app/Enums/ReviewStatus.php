<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Posted = 'posted';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
