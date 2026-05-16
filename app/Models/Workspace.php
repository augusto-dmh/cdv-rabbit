<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewSchemaVersion;
use App\Enums\ScmProvider;
use App\Enums\WorkspaceHealth;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'scm_provider',
        'scm_owner_slug',
        'bitbucket_token',
        'bitbucket_service_account',
        'webhook_secret',
        'github_installation_id',
        'kill_switch_enabled',
        'health',
        'daily_token_cap',
        'daily_token_cap_alert_threshold',
        'llm_provider',
        'review_schema_version',
    ];

    protected function casts(): array
    {
        return [
            'scm_provider' => ScmProvider::class,
            'bitbucket_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'kill_switch_enabled' => 'boolean',
            'health' => WorkspaceHealth::class,
            'review_schema_version' => ReviewSchemaVersion::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
