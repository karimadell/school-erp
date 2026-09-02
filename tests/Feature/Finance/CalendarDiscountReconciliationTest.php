<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

class CalendarDiscountReconciliationTest extends FinanceOperationsTestCase
{
    private function transport(string $amount, string $name = 'Transport'): Fee
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'A', 'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function issue(array $fees, ?string $discountType, string $discountValue)
    {
        $this->year->forceFill(['end_date' => '2027-06-30'])->save();
        $items = collect($fees)->map(fn (Fee $fee) => ['fee_id' => $fee->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'A'])->all();

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-06-30',
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'discount_type' => $discountType, 'discount_value' => $discountValue,
            'items' => $items,
        ], $this->accountant);
    }

    private function assertReconciled($invoice): void
    {
        $invoice->load('items', 'installments');
        $this->assertSame((string) $invoice->total_amount, bcadd((string) $invoice->items->sum('amount'), '0', 2));
        $this->assertSame((string) $invoice->total_amount, bcadd((string) $invoice->installments->sum('amount'), '0', 2));
        foreach ($invoice->items as $item) {
            $periodTotal = InstallmentCoveragePeriod::whereHas('coverage', fn ($q) => $q->where('invoice_item_id', $item->id))->sum('amount');
            $this->assertSame((string) $item->amount, bcadd((string) $periodTotal, '0', 2));
            $this->assertSame((string) $item->amount, $item->metadata['final_discounted_amount']);
        }
    }

    public function test_single_monthly_percentage_discount_reconciles_items_installments_and_coverage(): void
    {
        $invoice = $this->issue([$this->transport('1000.00')], 'percent', '10');
        $this->assertSame('9000.00', $invoice->total_amount);
        $this->assertReconciled($invoice);
    }

    public function test_single_monthly_fixed_discount_reconciles(): void
    {
        $invoice = $this->issue([$this->transport('1000.00')], 'fixed', '333.33');
        $this->assertSame('9666.67', $invoice->total_amount);
        $this->assertReconciled($invoice);
    }

    public function test_bundled_percentage_and_awkward_fixed_discount_reconcile_deterministically(): void
    {
        $a = $this->transport('1000.01', 'Transport A');
        $b = $this->transport('333.33', 'Transport B');
        $this->assertReconciled($this->issue([$a, $b], 'percent', '7.25'));

        $studentTwo = clone $this->student;
        unset($studentTwo);
        $this->assertReconciled($this->issue([$a, $b], 'fixed', '17.03'));
    }

    public function test_full_payment_of_discounted_invoice_leaves_no_period_capacity(): void
    {
        $invoice = $this->issue([$this->transport('1000.00')], 'percent', '10');
        $item = $invoice->items()->sole();
        foreach ($invoice->installments()->orderBy('sequence')->get() as $installment) {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $installment->amount,
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                installmentId: $installment->id, allocations: [['invoice_item_id' => $item->id, 'amount' => (string) $installment->amount]],
            );
        }
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        foreach (InstallmentCoveragePeriod::all() as $period) {
            $this->assertSame('0.00', $period->remainingAmount());
            $this->assertSame('settled', $period->settlementStatus());
        }
    }

    public function test_mismatched_calendar_schedule_is_rejected_before_any_installment_is_written(): void
    {
        $invoice = $this->invoice('100.00');
        try {
            app(\App\Services\Finance\InstallmentPlanService::class)->generateCalendarSchedule(
                $invoice, 'monthly', '2026-09-01', '2026-10-31', ['40.00', '40.00'],
            );
            $this->fail('Expected schedule reconciliation failure.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('services', $e->errors());
        }
        $this->assertSame(0, $invoice->installments()->count());
    }
}
