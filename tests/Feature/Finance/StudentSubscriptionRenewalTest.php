<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\StudentServiceSubscription;
use App\Models\Invoice;

class StudentSubscriptionRenewalTest extends FinanceOperationsTestCase
{
    public function test_renewal_copies_selected_service_to_target_year_idempotently_without_finance_history(): void
    {
        $admin=$this->user('admin'); $old=StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','end_date'=>'2027-06-30','status'=>'active','metadata'=>['zone'=>'A']]); $target=AcademicYear::create(['name'=>'2027/2028','start_date'=>'2027-09-01','end_date'=>'2028-06-30','is_active'=>true]); Enrollment::create(['student_id'=>$this->student->id,'academic_year_id'=>$target->id,'enrollment_mode_id'=>$this->enrollment->enrollment_mode_id,'stage_id'=>$this->enrollment->stage_id,'grade_id'=>$this->enrollment->grade_id,'class_id'=>$this->enrollment->class_id,'academic_year'=>$target->name,'enrollment_date'=>$target->start_date,'enrolled_at'=>$target->start_date,'status'=>'active','is_active'=>true]);
        $this->actingAs($admin)->get(route('dashboard.students.subscriptions.renew',$this->student))->assertOk()->assertSee('Продление услуг'); $this->actingAs($admin)->post(route('dashboard.students.subscriptions.renew.store',$this->student),['source_academic_year_id'=>$this->year->id,'target_academic_year_id'=>$target->id,'subscription_ids'=>[$old->id]])->assertSessionHasNoErrors();
        $copy=StudentServiceSubscription::where('enrollment_id','!=',$this->enrollment->id)->sole(); $this->assertSame($target->id,$copy->enrollment->academic_year_id); $this->assertSame('2027-09-01',$copy->start_date->toDateString()); $this->assertSame(['zone'=>'A'],$copy->metadata); $this->assertSame(0,Invoice::where('academic_year_id',$target->id)->count());
        $this->actingAs($admin)->post(route('dashboard.students.subscriptions.renew.store',$this->student),['source_academic_year_id'=>$this->year->id,'target_academic_year_id'=>$target->id,'subscription_ids'=>[$old->id]])->assertSessionHasNoErrors(); $this->assertSame(2,StudentServiceSubscription::count());
    }
}
