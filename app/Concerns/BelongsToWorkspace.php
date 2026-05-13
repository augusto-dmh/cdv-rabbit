<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait providing workspace-scoped tenancy enforcement.
 *
 * SECURITY: Every query on models using this trait is automatically scoped to
 * the current WorkspaceContext. If no context is bound, queries throw
 * WorkspaceContextMissingException (fail-closed by design — never make it lenient).
 *
 * Escape hatch: withoutWorkspaceScope() is available ONLY for console commands
 * (e.g. LgpdCheckCommand, PurgeStaleReviewsCommand). Callers must own the breach
 * and document why cross-tenant access is intentional.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $workspaceId = app(WorkspaceContext::class)->current();
            $builder->where((new static)->qualifyColumn('workspace_id'), $workspaceId);
        });

        static::creating(function (Model $model): void {
            if (empty($model->workspace_id)) {
                $model->workspace_id = app(WorkspaceContext::class)->current();
            }
        });
    }

    public static function withoutWorkspaceScope(): Builder
    {
        return static::withoutGlobalScope('workspace');
    }
}
