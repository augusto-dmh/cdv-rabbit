<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Exceptions\WorkspaceContextMissingException;

class WorkspaceContext
{
    protected ?int $workspaceId = null;

    public function bind(int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function current(): int
    {
        if ($this->workspaceId === null) {
            throw new WorkspaceContextMissingException;
        }

        return $this->workspaceId;
    }

    public function optional(): ?int
    {
        return $this->workspaceId;
    }

    public function clear(): void
    {
        $this->workspaceId = null;
    }

    public function bound(): bool
    {
        return $this->workspaceId !== null;
    }
}
