<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'enrollment_mode_id',
        'stage_id',
        'grade_id',
        'class_id',
        'academic_year',
        'enrollment_date',
        'enrolled_at',
        'status',
        'notes',
        'is_active',
        'registration_fee_charged_at',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
        'enrolled_at' => 'date',
        'is_active' => 'boolean',
        'registration_fee_charged_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * A fresh lookup, not the cached academicYear relation — the relation
     * can go stale within a single request/test if the AcademicYear row
     * is updated (e.g. deactivated) after it was first accessed on this
     * same model instance.
     */
    public function resolveAcademicYear(): ?AcademicYear
    {
        return AcademicYear::find($this->academic_year_id);
    }

    public function enrollmentMode()
    {
        return $this->belongsTo(EnrollmentMode::class);
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function serviceSubscriptions()
    {
        return $this->hasMany(StudentServiceSubscription::class);
    }

    public function mealSubscriptions()
    {
        return $this->hasMany(MealSubscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getDateAttribute()
    {
        return $this->enrollment_date ?? $this->enrolled_at;
    }

    public function getStatusLabelAttribute()
    {
        return match ($this->status) {
            'active' => __('enrollments.status_active'),
            'transferred' => __('enrollments.status_transferred'),
            'withdrawn' => __('enrollments.status_withdrawn'),
            'graduated' => __('enrollments.status_graduated'),
            default => '-',
        };
    }
}
