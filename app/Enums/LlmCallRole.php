<?php

declare(strict_types=1);

namespace App\Enums;

enum LlmCallRole: string
{
    case Triage = 'triage';
    case Review = 'review';
    case Summary = 'summary';
}
