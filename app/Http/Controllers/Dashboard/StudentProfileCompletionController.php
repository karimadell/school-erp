<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStudentProfileCompletionRequest;
use App\Models\AuditLog;
use App\Models\Student;
use App\Services\Students\StudentProfileCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StudentProfileCompletionController extends Controller
{
    public function __construct(private StudentProfileCompletionService $completion) { $this->middleware('permission:manage students')->except('completeReview'); $this->middleware('permission:manage users')->only('completeReview'); }

    public function edit(Student $student)
    {
        $student->load(['currentEnrollment.academicYear', 'currentEnrollment.stage', 'currentEnrollment.grade', 'currentEnrollment.schoolClass', 'invoices', 'files.uploader', 'representatives', 'emergencyContacts', 'educationalNeed']);

        return view('dashboard.students.complete-registration', ['student' => $student, 'completion' => $this->completion->calculate($student)]);
    }

    public function update(UpdateStudentProfileCompletionRequest $request, Student $student)
    {
        $newPhoto = null;
        try {
            if ($request->hasFile('photo')) $newPhoto = $request->file('photo')->store("students/{$student->id}/profile", config('filesystems.uploads.public'));
            DB::transaction(function () use ($request, $student, $newPhoto) {
                $old = $student->only(['last_name_ru','first_name_ru','patronymic_ru','phone','status','photo']);
                $profile = $student->documents ?? [];
                $representatives = collect($request->validated('representatives', []))->filter(fn ($item) => filled($item['full_name'] ?? null));
                foreach ($representatives as $item) {
                    $id = $item['id'] ?? null;
                    unset($item['id']);
                    foreach (['is_legal_representative', 'is_primary_contact', 'has_guardianship_authority'] as $flag) {
                        $item[$flag] = (bool) ($item[$flag] ?? false);
                    }
                    $representative = $id ? $student->representatives()->findOrFail($id) : $student->representatives()->make();
                    $representative->fill($item)->save();
                    if (in_array($representative->relationship_type, ['father', 'mother'], true)) {
                        $profile[$representative->relationship_type] = ['name' => $representative->full_name, 'phone' => $representative->phone, 'email' => $representative->email, 'identity' => data_get($profile, $representative->relationship_type.'.identity')];
                    }
                }
                if ($request->filled('father_name') || $request->filled('father_phone')) {
                    $profile['father'] = ['name' => $request->father_name, 'phone' => $request->father_phone, 'email' => $request->father_email, 'identity' => $request->father_identity];
                }
                if ($request->filled('mother_name') || $request->filled('mother_phone')) {
                    $profile['mother'] = ['name' => $request->mother_name, 'phone' => $request->mother_phone, 'email' => $request->mother_email, 'identity' => $request->mother_identity];
                }
                $emergencyContacts = collect($request->validated('emergency_contacts', []))->filter(fn ($item) => filled($item['full_name'] ?? null) && filled($item['phone'] ?? null));
                foreach ($emergencyContacts as $item) {
                    $id = $item['id'] ?? null;
                    unset($item['id']);
                    $contact = $id ? $student->emergencyContacts()->findOrFail($id) : $student->emergencyContacts()->make();
                    $contact->fill($item)->save();
                }
                if ($emergencyContacts->isNotEmpty()) {
                    $first = $emergencyContacts->sortBy('priority')->first();
                    $profile['emergency'] = ['name' => $first['full_name'], 'relationship' => $first['relationship'] ?? null, 'phone' => $first['phone']];
                } elseif ($request->filled('emergency_name') || $request->filled('emergency_phone')) {
                    $profile['emergency'] = ['name' => $request->emergency_name, 'relationship' => $request->emergency_relationship, 'phone' => $request->emergency_phone];
                }
                $profile['medical_notes'] = $request->medical_notes;
                $studentFields = ['last_name_ru', 'first_name_ru', 'patronymic_ru', 'gender', 'birth_date', 'nationality', 'phone', 'email', 'address', 'birth_place', 'citizenship_code', 'residential_address', 'registration_address', 'snils', 'inn'];
                $student->fill($request->safe()->only($studentFields));
                $student->last_name = $request->last_name_ru;
                $student->first_name = $request->first_name_ru;
                $student->patronymic = $request->patronymic_ru;
                $student->residential_address = $request->residential_address ?: $request->address ?: $student->residential_address;
                $student->address = $request->residential_address ?: $request->address ?: $student->address;
                $student->documents = $profile;
                if ($newPhoto) $student->photo = $newPhoto;
                $student->save();
                if ($student->currentEnrollment) {
                    $student->currentEnrollment->update($request->safe()->only(['admission_context', 'previous_school_name', 'previous_school_country_code', 'previous_grade', 'previous_class', 'previous_education_notes']));
                }
                $needValues = $request->safe()->only(['has_ovz', 'has_disability', 'requires_adapted_program', 'requires_special_conditions', 'special_conditions', 'consent_status', 'consent_received_at']);
                $needValues['notes'] = $request->input('educational_needs_notes');
                if (collect($needValues)->contains(fn ($value) => $value !== null && $value !== '')) {
                    $student->educationalNeed()->updateOrCreate([], $needValues);
                }
                $readiness = $this->completion->calculate($student->fresh(['currentEnrollment', 'files', 'representatives', 'emergencyContacts']));
                $status = $readiness['recommended_status'];
                if ($status === Student::STATUS_UNDER_REVIEW) {
                    $status = Student::STATUS_DOCUMENTS_REQUIRED;
                }
                if (! in_array($student->status, [Student::STATUS_UNDER_REVIEW, Student::STATUS_REGISTRATION_COMPLETED, 'suspended', 'graduated'], true)) {
                    $student->update(['status' => $status]);
                }
                if ($student->registration_status !== 'completed') {
                    $student->update(['registration_status' => $readiness['can_submit_for_review']
                        ? 'ready_for_review'
                        : ($readiness['basic_data_complete'] && $readiness['guardians_complete'] && $readiness['academic_complete'] ? 'documents_incomplete' : 'data_incomplete')]);
                }
                $this->audit($request, 'profile_updated', $student, $old, $student->fresh()->only(['last_name_ru', 'first_name_ru', 'patronymic_ru', 'phone', 'status', 'photo']));
            });
        } catch (\Throwable $e) { if ($newPhoto) Storage::disk(config('filesystems.uploads.public'))->delete($newPhoto); throw $e; }
        return back()->with('success', __('student_registration.responses.saved'));
    }

    public function submitReview(Request $request, Student $student)
    {
        $result = $this->completion->calculate($student);
        if (! $result['can_submit_for_review']) {
            throw ValidationException::withMessages(['profile' => __('student_registration.validation.registration.incomplete')]);
        }
        $old = $student->status;
        $student->update(['status' => Student::STATUS_UNDER_REVIEW]);
        $student->update(['registration_status' => 'ready_for_review', 'registration_submitted_at' => now()]);
        $this->audit($request, 'submitted_for_review', $student, ['status' => $old], ['status' => $student->status]);

        return back()->with('success', __('student_registration.responses.submitted'));
    }

    public function completeReview(Request $request, Student $student)
    {
        if ($student->status !== Student::STATUS_UNDER_REVIEW || ! $this->completion->calculate($student)['can_submit_for_review']) {
            abort(422, __('student_registration.validation.registration.not_ready'));
        }
        $student->update(['status' => Student::STATUS_REGISTRATION_COMPLETED, 'registration_status' => 'completed', 'registration_completed_at' => now(), 'registration_completed_by' => $request->user()->id]);
        $this->audit($request, 'registration_completed', $student, ['status' => Student::STATUS_UNDER_REVIEW], ['status' => $student->status]);

        return back()->with('success', __('student_registration.responses.completed'));
    }

    private function audit(Request $request, string $action, Student $student, array $old=[], array $new=[]): void
    { AuditLog::create(['user_id'=>$request->user()->id,'action'=>$action,'model'=>'Student','model_id'=>$student->id,'old_values'=>$old,'new_values'=>$new,'ip'=>$request->ip(),'user_agent'=>$request->userAgent()]); }
}
