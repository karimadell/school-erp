<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingBatchStudent extends Model
{
    public const MODE_INCLUDE = 'include';
    public const MODE_EXCLUDE = 'exclude';

    protected $fillable = [
        'billing_batch_id',
        'student_id',
        'mode',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
