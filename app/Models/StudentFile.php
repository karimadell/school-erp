<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFile extends Model
{
    public const TYPES = [
        'birth_certificate' => 'Свидетельство о рождении',
        'passport' => 'Паспорт',
        'residence_permit' => 'Вид на жительство',
        'previous_school' => 'Документ из предыдущей школы',
        'medical' => 'Медицинский документ',
        'other' => 'Другой документ',
    ];

    protected $fillable = [
        'student_id',
        'title',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'type',
        'category',
        'description',
        'issue_date',
        'expiry_date',
        'uploaded_by',
        'archived_at',
        'archived_by',
        'archive_reason',
        'document_type_id',
        'student_representative_id',
        'enrollment_id',
        'series',
        'document_number',
        'issued_by',
        'subdivision_code',
        'issuing_country_code',
        'verification_status',
        'verified_at',
        'verified_by',
        'metadata',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'archived_at' => 'datetime',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function archiver()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function representative()
    {
        return $this->belongsTo(StudentRepresentative::class, 'student_representative_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function typeLabel(): string
    {
        return $this->documentType?->localized_name ?? self::TYPES[$this->type] ?? __('student_registration.documents.other');
    }

    public function expiryStatus(): ?string
    {
        if (! $this->expiry_date) return null;
        if ($this->expiry_date->isPast()) return 'Просрочен';
        if ($this->expiry_date->lte(today()->addDays(30))) return 'Скоро истекает';
        return 'Действителен';
    }
}
