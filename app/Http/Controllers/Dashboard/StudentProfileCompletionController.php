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
        $student->load(['currentEnrollment.academicYear','currentEnrollment.stage','currentEnrollment.grade','currentEnrollment.schoolClass','invoices','files.uploader']);
        return view('dashboard.students.complete-registration', ['student'=>$student,'completion'=>$this->completion->calculate($student)]);
    }

    public function update(UpdateStudentProfileCompletionRequest $request, Student $student)
    {
        $newPhoto = null;
        try {
            if ($request->hasFile('photo')) $newPhoto = $request->file('photo')->store("students/{$student->id}/profile", 'public');
            DB::transaction(function () use ($request, $student, $newPhoto) {
                $old = $student->only(['last_name_ru','first_name_ru','patronymic_ru','phone','status','photo']);
                $profile = $student->documents ?? [];
                $profile['father'] = ['name'=>$request->father_name,'phone'=>$request->father_phone,'email'=>$request->father_email,'identity'=>$request->father_identity];
                $profile['mother'] = ['name'=>$request->mother_name,'phone'=>$request->mother_phone,'email'=>$request->mother_email,'identity'=>$request->mother_identity];
                $profile['emergency'] = ['name'=>$request->emergency_name,'relationship'=>$request->emergency_relationship,'phone'=>$request->emergency_phone];
                $profile['medical_notes'] = $request->medical_notes;
                $student->fill($request->safe()->except(['photo','father_name','father_phone','father_email','father_identity','mother_name','mother_phone','mother_email','mother_identity','emergency_name','emergency_relationship','emergency_phone','medical_notes']));
                $student->documents = $profile;
                if ($newPhoto) $student->photo = $newPhoto;
                $student->save();
                $status = $this->completion->calculate($student->fresh(['currentEnrollment','invoices','files']))['recommended_status'];
                if ($status === Student::STATUS_UNDER_REVIEW) $status = Student::STATUS_DOCUMENTS_REQUIRED;
                if (! in_array($student->status, [Student::STATUS_UNDER_REVIEW,Student::STATUS_REGISTRATION_COMPLETED,'suspended','graduated'], true)) $student->update(['status'=>$status]);
                $this->audit($request, 'profile_updated', $student, $old, $student->fresh()->only(['last_name_ru','first_name_ru','patronymic_ru','phone','status','photo']));
            });
        } catch (\Throwable $e) { if ($newPhoto) Storage::disk('public')->delete($newPhoto); throw $e; }
        return back()->with('success', 'Данные ученика сохранены.');
    }

    public function submitReview(Request $request, Student $student)
    {
        $result = $this->completion->calculate($student);
        if (! $result['can_submit_for_review']) throw ValidationException::withMessages(['profile'=>'У ученика отсутствуют обязательные данные или документы.']);
        $old = $student->status; $student->update(['status'=>Student::STATUS_UNDER_REVIEW]);
        $this->audit($request,'submitted_for_review',$student,['status'=>$old],['status'=>$student->status]);
        return back()->with('success','Личное дело отправлено на проверку.');
    }

    public function completeReview(Request $request, Student $student)
    {
        if ($student->status !== Student::STATUS_UNDER_REVIEW || ! $this->completion->calculate($student)['can_submit_for_review']) abort(422, 'Регистрация не готова к завершению.');
        $student->update(['status'=>Student::STATUS_REGISTRATION_COMPLETED]);
        $this->audit($request,'registration_completed',$student,['status'=>Student::STATUS_UNDER_REVIEW],['status'=>$student->status]);
        return back()->with('success','Регистрация ученика завершена.');
    }

    private function audit(Request $request, string $action, Student $student, array $old=[], array $new=[]): void
    { AuditLog::create(['user_id'=>$request->user()->id,'action'=>$action,'model'=>'Student','model_id'=>$student->id,'old_values'=>$old,'new_values'=>$new,'ip'=>$request->ip(),'user_agent'=>$request->userAgent()]); }
}
