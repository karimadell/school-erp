<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentSubscriptionRequest;
use App\Http\Requests\SubscriptionLifecycleRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\StudentSubscriptionLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentSubscriptionController extends Controller
{
    public function __construct(private StudentSubscriptionLifecycleService $lifecycle)
    {
        // Viewing existing entitlements follows the existing student/finance
        // read permissions; lifecycle mutations remain restricted to the
        // approved student-management permission.
        $this->middleware('permission:view students')->only(['index', 'show']);
        $this->middleware('permission:view invoices')->only(['control']);
        $this->middleware('permission:manage students')->except(['index', 'show', 'control']);
    }

    public function index(Request $request, Student $student): View
    {
        $subscriptions=$this->owned($student)->with(['fee','enrollment.academicYear','events.creator','invoiceItems.invoice'])->when($request->filled('academic_year_id'),fn($q)=>$q->whereHas('enrollment',fn($e)=>$e->where('academic_year_id',$request->integer('academic_year_id'))))->when($request->filled('status'),fn($q)=>$q->where('status',$request->input('status')))->latest('start_date')->get();
        return view('dashboard.students.subscriptions.index',['student'=>$student,'subscriptions'=>$subscriptions,'years'=>AcademicYear::orderByDesc('start_date')->get()]);
    }
    public function create(Student $student): View { return view('dashboard.students.subscriptions.form',['student'=>$student,'subscription'=>new StudentServiceSubscription,'enrollments'=>$student->enrollments()->with('academicYear')->get(),'fees'=>Fee::active()->orderBy('category')->orderBy('name_ru')->get()]); }
    public function store(StoreStudentSubscriptionRequest $request, Student $student): RedirectResponse
    { $data=$request->validated(); $enrollment=Enrollment::where('student_id',$student->id)->where('academic_year_id',$data['academic_year_id'])->where('is_active',true)->firstOrFail(); $subscription=$this->lifecycle->create($enrollment,Fee::findOrFail($data['fee_id']),$data,$request->user()); return redirect()->route('dashboard.students.subscriptions.show',[$student,$subscription])->with('success','Услуга успешно добавлена.'); }
    public function show(Student $student, StudentServiceSubscription $subscription): View { $this->assertOwner($student,$subscription); return view('dashboard.students.subscriptions.show',['student'=>$student,'subscription'=>$subscription->load(['fee','enrollment.academicYear','enrollment.grade','enrollment.schoolClass','events.creator','invoiceItems.invoice'])]); }
    public function edit(Student $student, StudentServiceSubscription $subscription): View { $this->assertOwner($student,$subscription); return view('dashboard.students.subscriptions.form',['student'=>$student,'subscription'=>$subscription,'enrollments'=>$student->enrollments()->with('academicYear')->get(),'fees'=>Fee::active()->orderBy('name_ru')->get()]); }
    public function update(StoreStudentSubscriptionRequest $request, Student $student, StudentServiceSubscription $subscription): RedirectResponse { $this->assertOwner($student,$subscription); $data=$request->validated(); if (($data['fee_id']??$subscription->fee_id)!==$subscription->fee_id) return $this->version($request,$student,$subscription); $subscription->forceFill(['end_date'=>$data['end_date']??$subscription->end_date,'quantity'=>$data['quantity'],'metadata'=>$data['metadata']??$subscription->metadata])->save(); return back()->with('success','Данные услуги обновлены. История счетов не изменена.'); }
    public function pause(SubscriptionLifecycleRequest $request, Student $student, StudentServiceSubscription $subscription): RedirectResponse { $this->assertOwner($student,$subscription); $this->lifecycle->pause($subscription,$request->input('effective_date'),$request->input('reason'),$request->user()); return back()->with('success','Услуга приостановлена.'); }
    public function resume(SubscriptionLifecycleRequest $request, Student $student, StudentServiceSubscription $subscription): RedirectResponse { $this->assertOwner($student,$subscription); $this->lifecycle->resume($subscription,$request->input('effective_date'),$request->input('reason'),$request->user()); return back()->with('success','Услуга возобновлена.'); }
    public function end(SubscriptionLifecycleRequest $request, Student $student, StudentServiceSubscription $subscription): RedirectResponse { $this->assertOwner($student,$subscription); $this->lifecycle->end($subscription,$request->input('effective_date'),$request->input('reason'),$request->user()); return back()->with('success','Услуга завершена.'); }
    public function renew(Student $student): View { return view('dashboard.students.subscriptions.renew',['student'=>$student,'years'=>AcademicYear::orderByDesc('start_date')->get(),'subscriptions'=>$this->owned($student)->with(['fee','enrollment.academicYear'])->where('status',StudentServiceSubscription::STATUS_ACTIVE)->get()]); }
    public function renewPreview(Request $request, Student $student): View { return $this->renew($student); }
    public function renewStore(Request $request, Student $student): RedirectResponse
    { $source=AcademicYear::findOrFail($request->integer('source_academic_year_id')); $target=AcademicYear::findOrFail($request->integer('target_academic_year_id')); $targetEnrollment=Enrollment::where('student_id',$student->id)->where('academic_year_id',$target->id)->where('is_active',true)->firstOrFail(); $created=0;$skipped=0; foreach($this->owned($student)->with('fee')->whereIn('id',$request->input('subscription_ids',[]))->whereHas('enrollment',fn($q)=>$q->where('academic_year_id',$source->id))->get() as $old){ if(!$old->fee?->is_active || $targetEnrollment->serviceSubscriptions()->where('fee_id',$old->fee_id)->whereIn('status',['active','suspended'])->exists()){ $skipped++; continue; } $this->lifecycle->create($targetEnrollment,$old->fee,['start_date'=>$target->start_date->toDateString(),'end_date'=>$target->end_date?->toDateString(),'quantity'=>$old->quantity,'metadata'=>$old->metadata,'reason'=>'Продление на учебный год '.$target->name],$request->user()); $created++; } return back()->with('success',"Продление выполнено. Создано: {$created}, пропущено: {$skipped}."); }
    public function control(Request $request): View { $subscriptions=StudentServiceSubscription::with(['fee','enrollment.student','enrollment.schoolClass','events'])->when($request->filled('status'),fn($q)=>$q->where('status',$request->input('status')))->when($request->filled('fee_id'),fn($q)=>$q->where('fee_id',$request->integer('fee_id')))->latest('start_date')->get(); return view('dashboard.finance.subscriptions.index',['subscriptions'=>$subscriptions,'fees'=>Fee::orderBy('name_ru')->get()]); }
    private function version(StoreStudentSubscriptionRequest $request, Student $student, StudentServiceSubscription $subscription): RedirectResponse { $this->assertOwner($student,$subscription); $data=$request->validated(); $new=$this->lifecycle->changeVariant($subscription,$data,$request->user()); return redirect()->route('dashboard.students.subscriptions.show',[$student,$new])->with('success','Создана новая версия услуги.'); }
    private function owned(Student $student) { return StudentServiceSubscription::whereHas('enrollment',fn($q)=>$q->where('student_id',$student->id)); }
    private function assertOwner(Student $student, StudentServiceSubscription $subscription): void { abort_unless($subscription->enrollment()->where('student_id',$student->id)->exists(),404); }
}
