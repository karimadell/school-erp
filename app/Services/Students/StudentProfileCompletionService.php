<?php

namespace App\Services\Students;

use App\Models\Student;

class StudentProfileCompletionService
{
    public const REQUIRED_DOCUMENTS = [
        'photo' => 'student_registration.readiness.document_items.photo',
        'birth_certificate' => 'student_registration.readiness.document_items.birth_certificate',
        'identity' => 'student_registration.readiness.document_items.identity',
        'previous_school' => 'student_registration.readiness.document_items.previous_school',
        'medical' => 'student_registration.readiness.document_items.medical',
    ];

    public function calculate(Student $student): array
    {
        $student->loadMissing(['currentEnrollment', 'files.documentType', 'representatives', 'emergencyContacts']);
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
        $studentDataItems = [
            'name' => (filled($student->last_name) && filled($student->first_name)) || (filled($student->last_name_ru) && filled($student->first_name_ru)) || filled($student->name),
            'birth_date' => filled($student->birth_date),
            'gender' => filled($student->gender),
            'citizenship' => filled($student->citizenship_code) || filled($student->nationality),
            'residential_address' => filled($student->residential_address) || filled($student->address),
            'phone' => filled($student->phone),
            'contact' => $student->representatives->contains(fn ($representative) => filled($representative->phone) || filled($representative->email))
                || $student->emergencyContacts->contains(fn ($contact) => filled($contact->phone))
                || collect(['father', 'mother'])->contains(fn ($key) => filled(data_get($student->documents, "{$key}.phone")))
                || filled(data_get($student->documents, 'emergency.phone')) || filled(data_get($student->documents, 'emergency_contact')),
        ];
        $studentDataCompleted = collect($studentDataItems)->filter()->count();
        $studentDataPercentage = (int) round($studentDataCompleted / count($studentDataItems) * 100);
        $basic = collect($studentDataItems)->except('contact')->every(fn ($complete) => $complete);
        $guardians = $studentDataItems['contact'];
        $enrollmentItems = [
            'academic_year' => filled($student->currentEnrollment?->academic_year_id),
            'stage' => filled($student->currentEnrollment?->stage_id),
            'grade' => filled($student->currentEnrollment?->grade_id),
            'class' => filled($student->currentEnrollment?->class_id),
            'enrollment_date' => filled($student->currentEnrollment?->date),
        ];
        $enrollmentCompleted = collect($enrollmentItems)->filter()->count();
        $enrollmentPercentage = (int) round($enrollmentCompleted / count($enrollmentItems) * 100);
        $academic = $enrollmentCompleted === count($enrollmentItems);
        $documentsCompleted = collect($requirements)->filter()->count();
        $documentsRequired = count($requirements);
        $documentsPercentage = (int) round($documentsCompleted / $documentsRequired * 100);
        $overall = (int) round(($studentDataPercentage + $documentsPercentage + $enrollmentPercentage) / 3);
        $canReview = $basic && $guardians && $academic && $documentsCompleted === $documentsRequired;
        $documentLabels = collect(self::REQUIRED_DOCUMENTS)->map(fn ($key) => __($key));

        return [
            'basic_data_complete' => $basic,
            'guardians_complete' => $guardians,
            'academic_complete' => $academic,
            'student_data_items' => $studentDataItems,
            'student_data_completed_count' => $studentDataCompleted,
            'student_data_required_count' => count($studentDataItems),
            'student_data_percentage' => $studentDataPercentage,
            'enrollment_items' => $enrollmentItems,
            'enrollment_completed_count' => $enrollmentCompleted,
            'enrollment_required_count' => count($enrollmentItems),
            'enrollment_percentage' => $enrollmentPercentage,
            'finance_present' => null,
            'document_requirements' => $documentLabels->map(fn ($label, $key) => ['label' => $label, 'complete' => $requirements[$key]])->all(),
            'documents_completed_count' => $documentsCompleted,
            'documents_required_count' => $documentsRequired,
            'documents_percentage' => $documentsPercentage,
            'overall_percentage' => $overall,
            'missing_document_items' => $documentLabels->reject(fn ($label, $key) => $requirements[$key])->values()->all(),
            'missing_student_items' => collect($studentDataItems)->reject()->keys()->map(fn ($key) => __('student_registration.readiness.items.'.$key))->values()->all(),
            'missing_enrollment_items' => collect($enrollmentItems)->reject()->keys()->map(fn ($key) => __('student_registration.readiness.items.'.$key))->values()->all(),
            'missing_items' => $documentLabels->reject(fn ($label, $key) => $requirements[$key])->values()->all(),
            'recommended_status' => $canReview ? Student::STATUS_UNDER_REVIEW : ($basic && $guardians ? Student::STATUS_DOCUMENTS_REQUIRED : Student::STATUS_PRE_REGISTERED),
            'can_submit_for_review' => $canReview,
        ];
    }
}
