<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    case Received = 'received';
    case Dispatched = 'dispatched';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
}
