<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BillingRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TRIGGER_MANUAL = 'manual';

    protected $fillable = [
        'billing_batch_id',
        'uuid',
        'trigger_type',
        'status',
        'executed_by',
        'processed_count',
        'created_count',
        'skipped_count',
        'failed_count',
        'total_amount',
        'started_at',
        'finished_at',
        'failure_summary',
    ];

    protected $casts = [
        'processed_count' => 'integer',
        'created_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'total_amount' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'failure_summary' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'trigger_type' => self::TRIGGER_MANUAL,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingRunItem::class);
    }
}
