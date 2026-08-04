<?php

namespace Tests\Feature\Finance;

use App\Models\StudentServiceSubscription;
use App\Services\Finance\StudentSubscriptionLifecycleService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StudentSubscriptionLifecycleTest extends FinanceOperationsTestCase
{
    public function test_pause_resume_and_end_preserve_events_and_dates(): void
    {
        $admin=$this->user('admin'); $subscription=StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active','metadata'=>['zone'=>'A']]); $service=app(StudentSubscriptionLifecycleService::class);
        $service->pause($subscription,'2026-09-01','Временная пауза',$admin); $this->assertSame('suspended',$subscription->fresh()->status);
        $service->resume($subscription,'2026-10-01','Услуга возобновлена',$admin); $this->assertSame('active',$subscription->fresh()->status);
        $service->end($subscription,'2027-01-01','Окончание обучения',$admin); $fresh=$subscription->fresh();
        $this->assertSame(StudentServiceSubscription::STATUS_ENDED,$fresh->status); $this->assertSame('2027-01-01',$fresh->end_date->toDateString()); $this->assertSame(3,$fresh->events()->count());
    }

    public function test_invalid_transition_and_overlap_are_rejected(): void
    {
        $admin=$this->user('admin'); $subscription=StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active']); $service=app(StudentSubscriptionLifecycleService::class);
        try {$service->pause($subscription,'2026-07-01','Пауза',$admin);$this->fail('Неверная дата должна быть отклонена.');} catch(ValidationException){$this->assertTrue(true);}
        $this->actingAs($admin)->post(route('dashboard.students.subscriptions.store',$this->student),['academic_year_id'=>$this->year->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-09-01','quantity'=>1])->assertSessionHasErrors();
    }

    public function test_version_change_ends_old_subscription_and_preserves_invoice_link(): void
    {
        $admin=$this->user('admin'); $old=StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active','metadata'=>['zone'=>'A']]); $invoice=$this->invoice(); $item=$invoice->items()->first(); $item->update(['subscription_id'=>$old->id]);
        $new=app(StudentSubscriptionLifecycleService::class)->changeVariant($old,['start_date'=>'2026-11-01','end_date'=>null,'quantity'=>1,'metadata'=>['zone'=>'B']],$admin);
        $this->assertSame('2026-10-31',$old->fresh()->end_date->toDateString()); $this->assertSame('active',$new->status); $this->assertSame($old->id,$item->fresh()->subscription_id); $this->assertSame('B',$new->metadata['zone']);
    }
}
