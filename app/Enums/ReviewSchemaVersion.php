<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewSchemaVersion: string
{
    case V1 = 'v1';
    case V2 = 'v2';
}
