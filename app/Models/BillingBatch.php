<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BillingBatch extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TARGET_MODE_ALL = 'all';
    public const TARGET_MODE_CLASSES = 'classes';

    protected $fillable = [
        'uuid',
        'academic_year_id',
        'fee_id',
        'quantity',
        'currency',
        'issue_date',
        'due_date',
        'description',
        'target_mode',
        'status',
        'created_by',
        'executed_by',
        'selected_count',
        'eligible_count',
        'skipped_count',
        'expected_invoice_count',
        'expected_total_amount',
        'preview_snapshot',
        'previewed_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'issue_date' => 'date',
        'due_date' => 'date',
        'selected_count' => 'integer',
        'eligible_count' => 'integer',
        'skipped_count' => 'integer',
        'expected_invoice_count' => 'integer',
        'expected_total_amount' => 'decimal:2',
        'preview_snapshot' => 'array',
        'previewed_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'quantity' => 1,
        'target_mode' => self::TARGET_MODE_CLASSES,
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->uuid ??= (string) Str::uuid();
        });
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function classTargets(): HasMany
    {
        return $this->hasMany(BillingBatchClass::class);
    }

    public function studentTargets(): HasMany
    {
        return $this->hasMany(BillingBatchStudent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BillingRun::class);
    }

    public function latestRun(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BillingRun::class)->latestOfMany();
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    public function includedStudentIds(): \Illuminate\Support\Collection
    {
        return $this->studentTargets->where('mode', BillingBatchStudent::MODE_INCLUDE)->pluck('student_id');
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    public function excludedStudentIds(): \Illuminate\Support\Collection
    {
        return $this->studentTargets->where('mode', BillingBatchStudent::MODE_EXCLUDE)->pluck('student_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
