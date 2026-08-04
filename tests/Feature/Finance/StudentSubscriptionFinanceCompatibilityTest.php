<?php

namespace Tests\Feature\Finance;

use App\Models\InvoiceItem;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\StudentSubscriptionLifecycleService;

class StudentSubscriptionFinanceCompatibilityTest extends FinanceOperationsTestCase
{
    public function test_lifecycle_does_not_mutate_historical_invoice_item(): void
    {
        $admin=$this->user('admin'); $subscription=StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active']); $invoice=$this->invoice(); $item=$invoice->items()->first(); $item->update(['subscription_id'=>$subscription->id]); $snapshot=$item->fresh()->toArray();
        app(StudentSubscriptionLifecycleService::class)->end($subscription,'2026-12-01','Завершение',$admin);
        $this->assertSame($snapshot,$item->fresh()->toArray()); $this->assertSame($subscription->id,InvoiceItem::find($item->id)->subscription_id);
    }
}
