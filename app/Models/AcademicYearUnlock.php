<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYearUnlock extends Model
{
    protected $fillable = [
        'academic_year_id',
        'reason',
        'unlocked_by',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function unlockedBy()
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
