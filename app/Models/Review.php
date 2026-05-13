<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'repository_id',
        'pull_request_number',
        'head_sha',
        'base_sha',
        'status',
        'started_at',
        'finished_at',
        'summary_comment_id',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd_cents',
        'secrets_redacted',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasMany<ReviewComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }

    /** @return HasMany<ReviewsLlmCall, $this> */
    public function llmCalls(): HasMany
    {
        return $this->hasMany(ReviewsLlmCall::class);
    }
}
