<?php

namespace App\Services\Students;

use App\Models\Student;

class StudentProfileCompletionService
{
    public const REQUIRED_DOCUMENTS = [
        'photo' => 'Фото ученика',
        'birth_certificate' => 'Свидетельство о рождении',
        'identity' => 'Паспорт или вид на жительство',
        'previous_school' => 'Документ из предыдущей школы',
        'medical' => 'Медицинский документ',
    ];

    public function calculate(Student $student): array
    {
        $student->loadMissing(['currentEnrollment', 'invoices', 'files']);
        $profile = $student->documents ?? [];
        $activeFiles = $student->files->whereNull('archived_at');
        $validIdentity = $activeFiles->whereIn('type', ['passport', 'residence_permit'])
            ->contains(fn ($file) => ! $file->expiry_date || ! $file->expiry_date->isPast());
        $requirements = [
            'photo' => filled($student->photo),
            'birth_certificate' => $activeFiles->contains('type', 'birth_certificate'),
            'identity' => $validIdentity,
            'previous_school' => $activeFiles->contains('type', 'previous_school'),
            'medical' => $activeFiles->contains('type', 'medical'),
        ];
        $basic = filled($student->last_name_ru) && filled($student->first_name_ru)
            && filled($student->birth_date) && filled($student->gender)
            && filled($student->nationality) && filled($student->address) && filled($student->phone);
        $guardians = collect([
            data_get($profile, 'father.phone'), data_get($profile, 'mother.phone'), data_get($profile, 'emergency.phone'),
        ])->contains(fn ($phone) => filled($phone));
        $academic = $student->currentEnrollment !== null;
        $documentsCompleted = collect($requirements)->filter()->count();
        $documentsRequired = count($requirements);
        $documentsPercentage = (int) round($documentsCompleted / $documentsRequired * 100);
        $overall = (int) round((collect([$basic, $guardians, $academic])->filter()->count() + $documentsCompleted) / (3 + $documentsRequired) * 100);
        $canReview = $basic && $guardians && $academic && $documentsCompleted === $documentsRequired;

        return [
            'basic_data_complete' => $basic,
            'guardians_complete' => $guardians,
            'academic_complete' => $academic,
            'finance_present' => $student->invoices->isNotEmpty(),
            'document_requirements' => collect(self::REQUIRED_DOCUMENTS)->map(fn ($label, $key) => ['label' => $label, 'complete' => $requirements[$key]])->all(),
            'documents_completed_count' => $documentsCompleted,
            'documents_required_count' => $documentsRequired,
            'documents_percentage' => $documentsPercentage,
            'overall_percentage' => $overall,
            'missing_items' => collect(self::REQUIRED_DOCUMENTS)->reject(fn ($label, $key) => $requirements[$key])->values()->all(),
            'recommended_status' => $canReview ? Student::STATUS_UNDER_REVIEW : ($basic && $guardians ? Student::STATUS_DOCUMENTS_REQUIRED : Student::STATUS_PRE_REGISTERED),
            'can_submit_for_review' => $canReview,
        ];
    }
}
