<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'bitbucket_uuid',
        'repository_id',
        'event_type',
        'status',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
