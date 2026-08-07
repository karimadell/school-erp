<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRunItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'billing_run_id',
        'student_id',
        'enrollment_id',
        'fee_id',
        'invoice_id',
        'status',
        'skip_reason',
        'unit_price',
        'quantity',
        'amount',
        'context',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'amount' => 'decimal:2',
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class, 'billing_run_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
