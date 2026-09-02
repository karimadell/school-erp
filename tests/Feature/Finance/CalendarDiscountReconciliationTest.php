<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationCoveragePeriod;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class CalendarDiscountReconciliationTest extends FinanceOperationsTestCase
{
    private function transport(string $amount, string $name = 'Transport'): Fee
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'A', 'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function tuition(string $amount): Fee
    {
        $fee = Fee::create(['name_ru' => 'Tuition', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function issue(array $fees, ?string $discountType, string $discountValue, ?string $idempotencyKey = null)
    {
        $this->year->forceFill(['end_date' => '2027-06-30'])->save();
        $items = collect($fees)->map(fn (Fee $fee) => $fee->category === Fee::CATEGORY_TRANSPORT
            ? ['fee_id' => $fee->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'A']
            : ['fee_id' => $fee->id, 'payment_period' => 'monthly'])->all();

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-06-30',
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'discount_type' => $discountType, 'discount_value' => $discountValue,
            'items' => $items,
        ], $this->accountant, idempotencyKey: $idempotencyKey);
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

    #[DataProvider('fullWaiverProvider')]
    public function test_full_waiver_is_a_zero_balance_settled_graph_without_fake_cash(string $type, string $value): void
    {
        $key = (string) Str::uuid();
        $invoice = $this->issue([$this->transport('1000.00')], $type, $value, $key);
        $replay = $this->issue([$invoice->items()->sole()->fee], $type, $value, $key);

        $this->assertSame($invoice->id, $replay->id);
        $invoice->refresh()->load('items', 'installments');
        $this->assertSame('0.00', $invoice->total_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertFalse(Invoice::overdue('2028-01-01')->whereKey($invoice)->exists());
        $this->assertSame('0.00', bcadd((string) $invoice->items->sum('amount'), '0', 2));
        $this->assertSame('0.00', bcadd((string) $invoice->installments->sum('amount'), '0', 2));
        $this->assertTrue($invoice->installments->every(fn ($installment) => $installment->derivedStatus() === 'paid'));
        $this->assertSame(1, ServiceCoverage::whereHas('invoiceItem', fn ($query) => $query->where('invoice_id', $invoice->id))->count());
        $periods = InstallmentCoveragePeriod::whereHas('coverage.invoiceItem', fn ($query) => $query->where('invoice_id', $invoice->id))->get();
        $this->assertNotEmpty($periods);
        foreach ($periods as $period) {
            $this->assertSame('0.00', (string) $period->amount);
            $this->assertSame('0.00', $period->remainingAmount());
            $this->assertSame('settled', $period->settlementStatus());
        }
        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, PaymentAllocation::whereHas('payment', fn ($query) => $query->where('invoice_id', $invoice->id))->count());
        $this->assertSame(0, PaymentAllocationCoveragePeriod::whereHas('allocation.payment', fn ($query) => $query->where('invoice_id', $invoice->id))->count());
    }

    public static function fullWaiverProvider(): array
    {
        return [['percent', '100'], ['fixed', '10000.00']];
    }

    public function test_bundled_full_waiver_and_near_total_discount_remain_coherent(): void
    {
        $tuition = $this->tuition('1000.00');
        $transport = $this->transport('333.33');
        $waived = $this->issue([$tuition, $transport], 'percent', '100');
        $this->assertReconciled($waived);
        $this->assertSame('0.00', (string) $waived->total_amount);
        $this->assertSame(Invoice::STATUS_PAID, $waived->status);
        $this->assertSame(0, InvoicePayment::where('invoice_id', $waived->id)->count());

        $fixedWaived = $this->issue([$tuition, $transport], 'fixed', (string) $waived->subtotal_amount);
        $this->assertReconciled($fixedWaived);
        $this->assertSame('0.00', (string) $fixedWaived->total_amount);
        $this->assertSame(Invoice::STATUS_PAID, $fixedWaived->status);

        $nearTotalDiscount = bcsub((string) $waived->subtotal_amount, '0.01', 2);
        $near = $this->issue([$tuition, $transport], 'fixed', $nearTotalDiscount);
        $this->assertReconciled($near);
        $this->assertSame('0.01', (string) $near->total_amount);
        $this->assertSame('0.01', (string) $near->remaining_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $near->status);
        $this->assertSame('0.01', bcadd((string) $near->installments()->sum('amount'), '0', 2));
        $this->assertSame(1, $near->installments()->where('amount', '0.01')->count());
    }
}
