<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RedactionApplied
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $workspaceId,
        public readonly array $redactedKeys,
    ) {}
}
