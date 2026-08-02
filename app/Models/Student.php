<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolClass;

class Student extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PRE_REGISTERED = 'pre_registered';

    protected $fillable = [
        'name',
        'status',
        'academic_year',
        

        'class_id',
        'first_name',
        'last_name',
        'first_name_ru',
        'last_name_ru',
        'patronymic_ru',
        'birth_date',
        'gender',
        'phone',
        'email',
        'nationality',
        'address',
        'photo',
        'documents',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'documents' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Student $student): void {
            foreach (['last_name_ru', 'first_name_ru', 'patronymic_ru'] as $field) {
                if ($student->{$field} !== null) {
                    $student->{$field} = self::normalizeRussianNamePart($student->{$field});
                }
            }

            if (filled($student->last_name_ru) && filled($student->first_name_ru)) {
                $student->name = $student->russianFullName();
            }
        });
    }

    public static function normalizeRussianNamePart(?string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    public function russianFullName(): string
    {
        $structured = collect([$this->last_name_ru, $this->first_name_ru, $this->patronymic_ru])
            ->map(fn ($part) => self::normalizeRussianNamePart($part))
            ->filter()
            ->implode(' ');

        return $structured !== '' ? $structured : trim((string) $this->name);
    }

    public function class()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentEnrollment()
    {
        // The student's current placement is the enrollment linked to
        // whichever AcademicYear is currently active — not whichever
        // enrollment happens to have is_active = true. is_active on
        // Enrollment describes only that record's own year and is never a
        // cross-year "current" flag.
        return $this->hasOne(Enrollment::class)
            ->whereHas('academicYear', fn ($query) => $query->where('is_active', true));
    }

    public function attendances()
    {
        return $this->hasManyThrough(
            Attendance::class,
            Enrollment::class,
            'student_id',
            'enrollment_id',
            'id',
            'id'
        );
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function grades()
    {
        return $this->hasMany(StudentGrade::class);
    }

    public function getFullNameAttribute()
    {
        return $this->russianFullName();
    }

    public function getShortNameAttribute()
    {
        if (! filled($this->last_name_ru) || ! filled($this->first_name_ru)) {
            return trim((string) $this->name);
        }

        $last = $this->last_name_ru;

        $firstInitial = $this->first_name_ru
            ? mb_substr($this->first_name_ru, 0, 1) . '.'
            : '';

        $patronymicInitial = $this->patronymic_ru
            ? mb_substr($this->patronymic_ru, 0, 1) . '.'
            : '';

        return trim($last . ' ' . $firstInitial . $patronymicInitial);
    }

    public function averageGrade()
    {
        return round($this->grades()->avg('score') ?? 0, 2);
    }

    public function getProfileCompletionPercentageAttribute(): int
    {
        $requiredParts = [
            filled($this->last_name_ru) && filled($this->first_name_ru) || filled($this->name),
            filled($this->phone), filled($this->birth_date), filled($this->gender),
            filled($this->nationality), filled($this->address), filled($this->email), filled($this->documents),
        ];
        $completed = collect($requiredParts)->filter()->count();

        return (int) round(($completed / count($requiredParts)) * 100);
    }

    public function getHasIncompleteProfileAttribute(): bool
    {
        return $this->status === self::STATUS_PRE_REGISTERED || $this->profile_completion_percentage < 100;
    }

    public function yearGrade($subjectId)
    {
        return round(
            $this->grades()
                ->where('subject_id', $subjectId)
                ->avg('score') ?? 0,
            2
        );
    }
}
