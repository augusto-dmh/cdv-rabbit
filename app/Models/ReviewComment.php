<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\CommentType;
use Database\Factories\ReviewCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewComment extends Model
{
    /** @use HasFactory<ReviewCommentFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'review_id',
        'workspace_id',
        'file_path',
        'line',
        'bitbucket_comment_id',
        'posted_at',
        'comment_type',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'comment_type' => CommentType::class,
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
