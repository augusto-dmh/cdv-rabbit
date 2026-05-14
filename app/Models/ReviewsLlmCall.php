<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\LlmCallRole;
use Database\Factories\ReviewsLlmCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReviewsLlmCall extends Model
{
    /** @use HasFactory<ReviewsLlmCallFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'review_id',
        'workspace_id',
        'model_id',
        'role',
        'input_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
        'output_tokens',
        'request_id',
        'ratelimit_tokens_remaining',
        'ratelimit_tokens_reset',
        'duration_ms',
        'http_status',
        'error_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => LlmCallRole::class,
            'ratelimit_tokens_reset' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
