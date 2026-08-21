<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolClass;

class Student extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PRE_REGISTERED = 'pre_registered';
    public const STATUS_DOCUMENTS_REQUIRED = 'documents_required';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_REGISTRATION_COMPLETED = 'registration_completed';

    public const REGISTRATION_STATUSES = ['draft', 'data_incomplete', 'documents_incomplete', 'ready_for_review', 'completed'];

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
        'birth_place',
        'citizenship_code',
        'residential_address',
        'registration_address',
        'snils',
        'inn',
        'registration_status',
        'registration_submitted_at',
        'registration_completed_at',
        'registration_completed_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'documents' => 'array',
        'registration_submitted_at' => 'datetime',
        'registration_completed_at' => 'datetime',
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
                $student->last_name ??= $student->last_name_ru;
                $student->first_name ??= $student->first_name_ru;
                $student->patronymic ??= $student->patronymic_ru;
            }

            $student->residential_address ??= $student->address;
            $student->registration_status ??= match ($student->status ?? self::STATUS_ACTIVE) {
                self::STATUS_REGISTRATION_COMPLETED, self::STATUS_ACTIVE, 'suspended', 'graduated' => 'completed',
                self::STATUS_DOCUMENTS_REQUIRED => 'documents_incomplete',
                self::STATUS_UNDER_REVIEW => 'ready_for_review',
                default => 'draft',
            };
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

    public function files()
    {
        return $this->hasMany(StudentFile::class);
    }

    public function representatives()
    {
        return $this->hasMany(StudentRepresentative::class);
    }

    public function emergencyContacts()
    {
        return $this->hasMany(StudentEmergencyContact::class)->orderBy('priority');
    }

    public function educationalNeed()
    {
        return $this->hasOne(StudentEducationalNeed::class);
    }

    public function registrationCompletedBy()
    {
        return $this->belongsTo(User::class, 'registration_completed_by');
    }

    public function representativeData(string $relationship): ?array
    {
        $representative = $this->relationLoaded('representatives')
            ? $this->representatives->firstWhere('relationship_type', $relationship)
            : $this->representatives()->where('relationship_type', $relationship)->first();

        if ($representative) {
            return $representative->toArray();
        }

        $legacy = data_get($this->documents, $relationship);

        return is_array($legacy) && collect($legacy)->filter(fn ($value) => filled($value))->isNotEmpty() ? $legacy : null;
    }

    public function emergencyContactData(): ?array
    {
        $contact = $this->relationLoaded('emergencyContacts')
            ? $this->emergencyContacts->sortBy('priority')->first()
            : $this->emergencyContacts()->first();

        if ($contact) {
            return $contact->toArray();
        }

        $legacy = data_get($this->documents, 'emergency');
        if (is_array($legacy) && collect($legacy)->filter(fn ($value) => filled($value))->isNotEmpty()) {
            return $legacy;
        }
        $legacyText = data_get($this->documents, 'emergency_contact');

        return filled($legacyText) ? ['name' => $legacyText] : null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PRE_REGISTERED => 'Предварительная регистрация',
            self::STATUS_DOCUMENTS_REQUIRED => 'Требуются документы',
            self::STATUS_UNDER_REVIEW => 'На проверке',
            self::STATUS_REGISTRATION_COMPLETED => 'Регистрация завершена',
            'suspended' => 'Приостановлен',
            'graduated' => 'Выпускник',
            default => 'Активен',
        };
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
        return app(\App\Services\Students\StudentProfileCompletionService::class)
            ->calculate($this)['student_data_percentage'];
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
