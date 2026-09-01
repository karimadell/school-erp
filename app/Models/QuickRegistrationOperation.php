<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Finance V2, Phase 2D corrective pass #2 (HIGH 2 — Quick Registration
 * operation-level idempotency). See the migration's own docblock for the
 * full design rationale.
 */
class QuickRegistrationOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'idempotency_key',
        'payload_hash',
        'status',
        'student_id',
        'enrollment_id',
        'invoice_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
