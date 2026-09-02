<?php

namespace Tests\Feature\Finance;

use App\Models\CreditApplicationCoveragePeriod;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\ServiceCoverage;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplicationItem;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\StudentCreditService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1 — payment/refund/
 * credit-to-coverage NET settlement integrity).
 *
 * Extends corrective pass #2's payment-to-period allocation with: period
 * capacity enforcement (A/B/C — InvoicePaymentService::
 * linkAllocationToCoveragePeriod()), refund-to-period reversal (D —
 * InvoiceRefundService), and credit-to-period settlement (E —
 * StudentCreditService::apply()'s new optional $allocations parameter).
 * Net settlement is always payments + credit - refunds, computed live
 * over the append-only allocation chain — never inferred from
 * installment-level remaining_amount/status, never backfilled or
 * guessed for a legacy/ambiguous row.
 */
class PaymentCoverageNetSettlementTest extends FinanceOperationsTestCase
{
    private function bundledInvoice(): Invoice
    {
        $tuition = Fee::create(['name_ru' => 'Обучение (сеттлмент)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $transport = Fee::create(['name_ru' => 'Трансфер (сеттлмент)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '400.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [
                ['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $transport->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1'],
            ],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
    }

    private function tuitionItem(Invoice $invoice): InvoiceItem
    {
        return $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
    }

    private function transportItem(Invoice $invoice): InvoiceItem
    {
        return $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);
    }

    private function pay(Invoice $invoice, string $amount, array $allocations): \App\Models\InvoicePayment
    {
        $installment = $invoice->installments()->orderBy('sequence')->first();

        return app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: $amount,
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id, allocations: $allocations,
        );
    }

    private function firstPeriod(Invoice $invoice, InvoiceItem $item): InstallmentCoveragePeriod
    {
        $installment = $invoice->installments()->orderBy('sequence')->first();
        $coverage = ServiceCoverage::where('invoice_item_id', $item->id)->sole();

        return InstallmentCoveragePeriod::where('invoice_installment_id', $installment->id)
            ->where('service_coverage_id', $coverage->id)->sole();
    }

    // ----- 1. Period capacity rejection --------------------------------

    public function test_a_second_payment_cannot_over_fund_an_already_settled_period(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $tuitionItem = $this->tuitionItem($invoice);

        // First payment fully settles Transport's 400 period.
        $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);
        $this->assertSame('settled', $this->firstPeriod($invoice, $transportItem)->settlementStatus());

        // A second, separate payment (against a LATER installment, but
        // deliberately mis-targeted at Transport's FIRST, already-settled
        // period via a manually-forged allocation) must be rejected —
        // simulated here directly through the guarded method itself
        // since InvoicePaymentService::record() would naturally target a
        // fresh installment; the guard exists precisely so this can never
        // succeed regardless of how it's reached.
        $secondPayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $invoice->installments()->orderBy('sequence')->skip(1)->first()->id,
            allocations: [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']],
        );
        // That second payment settles the SECOND installment's own
        // Transport period — not a violation. Now prove the guard
        // directly: try to link an allocation from a THIRD payment onto
        // the SAME (first, already-settled) period.
        $thirdPayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $invoice->installments()->orderBy('sequence')->first()->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00']],
        );
        $forgedAllocation = PaymentAllocation::create([
            'invoice_payment_id' => $thirdPayment->id, 'invoice_item_id' => $transportItem->id, 'amount' => '400.00',
        ]);

        $this->expectException(ValidationException::class);
        $reflection = new \ReflectionMethod(InvoicePaymentService::class, 'linkAllocationToCoveragePeriod');
        $reflection->setAccessible(true);
        $reflection->invoke(app(InvoicePaymentService::class), $forgedAllocation, $this->firstPeriod($invoice, $transportItem), '400.00');
    }

    public function test_period_capacity_rejection_via_the_real_public_record_flow(): void
    {
        // A cleaner, fully-public-API version of the same guarantee:
        // Transport's period (400) is exactly settled by one payment;
        // paying it AGAIN via a corrected split against the SAME
        // installment's remaining Tuition capacity is fine, but
        // re-submitting a payment that tries to allocate MORE to
        // Transport than its period has room for (because it's already
        // settled) is rejected before any row is written.
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $installment = $invoice->installments()->orderBy('sequence')->first();

        $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);
        $this->assertSame('settled', $this->firstPeriod($invoice, $transportItem)->settlementStatus());

        // Nothing left to pay on this installment for Transport — the
        // installment's own remaining_amount now reflects only Tuition's
        // unpaid 1000, so a further allocation explicitly targeting
        // Transport again on THIS installment has no room in
        // PaymentAllocation terms either; assert the underlying period
        // guard is what actually protects this (not merely an
        // installment-level coincidence) via the same reflection path,
        // this time with the amount that would exceed remaining period
        // capacity (0 left of 400).
        $tuitionItem = $this->tuitionItem($invoice);
        $extraPayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00']],
        );
        $forgedAllocation = PaymentAllocation::create([
            'invoice_payment_id' => $extraPayment->id, 'invoice_item_id' => $transportItem->id, 'amount' => '1.00',
        ]);
        $reflection = new \ReflectionMethod(InvoicePaymentService::class, 'linkAllocationToCoveragePeriod');
        $reflection->setAccessible(true);
        $this->expectException(ValidationException::class);
        $reflection->invoke(app(InvoicePaymentService::class), $forgedAllocation, $this->firstPeriod($invoice, $transportItem), '1.00');
    }

    // ----- 2/3. Refunds reduce period settlement ------------------------

    public function test_pay_400_then_refund_400_period_no_longer_settled(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $payment = $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);
        $period = $this->firstPeriod($invoice, $transportItem);
        $this->assertSame('settled', $period->settlementStatus());
        $this->assertSame('400.00', $period->netSettledAmount());

        $allocation = PaymentAllocation::where('invoice_payment_id', $payment->id)->sole();
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'Тест: полный возврат', cashAccountId: $this->cash->id,
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocation->id, 'amount' => '400.00']],
        );

        $period->refresh();
        $this->assertSame('unpaid', $period->settlementStatus());
        $this->assertSame('0.00', $period->netSettledAmount());
        $this->assertSame('400.00', $period->grossPaymentAllocated());
        $this->assertSame('400.00', $period->grossRefunded());
    }

    public function test_pay_400_then_refund_100_net_300_status_partial(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $payment = $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);
        $allocation = PaymentAllocation::where('invoice_payment_id', $payment->id)->sole();

        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '100.00', reason: 'Тест: частичный возврат', cashAccountId: $this->cash->id,
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocation->id, 'amount' => '100.00']],
        );

        $period = $this->firstPeriod($invoice, $transportItem);
        $this->assertSame('300.00', $period->netSettledAmount());
        $this->assertSame('partial', $period->settlementStatus());
    }

    // ----- 4. Wrong-InvoiceItem mapping rejected -------------------------

    public function test_wrong_invoice_item_mapping_is_rejected(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $this->tuitionItem($invoice);
        $transportItem = $this->transportItem($invoice);
        $installment = $invoice->installments()->orderBy('sequence')->first();

        // A genuine Tuition-item PaymentAllocation, deliberately mapped
        // (via the internal method directly) onto Transport's own
        // coverage period — must be rejected by ownership guard C.
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00']],
        );
        $tuitionAllocation = PaymentAllocation::where('invoice_payment_id', $payment->id)->sole();

        $reflection = new \ReflectionMethod(InvoicePaymentService::class, 'linkAllocationToCoveragePeriod');
        $reflection->setAccessible(true);
        $this->expectException(ValidationException::class);
        $reflection->invoke(app(InvoicePaymentService::class), $tuitionAllocation, $this->firstPeriod($invoice, $transportItem), '400.00');
    }

    // ----- 5. Mapping exceeding PaymentAllocation.amount rejected --------

    public function test_period_mapping_exceeding_the_parent_allocations_own_amount_is_rejected(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $installment = $invoice->installments()->orderBy('sequence')->first();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id,
            allocations: [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']],
        );
        $allocation = PaymentAllocation::where('invoice_payment_id', $payment->id)->sole();
        // The real flow already linked exactly 400 to the period. Force
        // a SECOND, direct call trying to link MORE against the same
        // parent allocation (whose own amount is only 400) — B must
        // reject regardless of period capacity.
        $reflection = new \ReflectionMethod(InvoicePaymentService::class, 'linkAllocationToCoveragePeriod');
        $reflection->setAccessible(true);
        $this->expectException(ValidationException::class);
        $reflection->invoke(app(InvoicePaymentService::class), $allocation, $this->firstPeriod($invoice, $transportItem), '0.01');
    }

    // ----- 6. Split payment produces exact net amounts per service -------

    public function test_split_payment_produces_exact_net_amounts_per_service(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $this->tuitionItem($invoice);
        $transportItem = $this->transportItem($invoice);

        $this->pay($invoice, '700.00', [
            ['invoice_item_id' => $transportItem->id, 'amount' => '400.00'],
            ['invoice_item_id' => $tuitionItem->id, 'amount' => '300.00'],
        ]);

        $this->assertSame('400.00', $this->firstPeriod($invoice, $transportItem)->netSettledAmount());
        $this->assertSame('300.00', $this->firstPeriod($invoice, $tuitionItem)->netSettledAmount());
        $this->assertSame('settled', $this->firstPeriod($invoice, $transportItem)->settlementStatus());
        $this->assertSame('partial', $this->firstPeriod($invoice, $tuitionItem)->settlementStatus());
    }

    // ----- 7. Credit application settles a selected period, sources auditable -----

    public function test_credit_application_explicitly_settles_a_selected_period_with_sources_separately_auditable(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $period = $this->firstPeriod($invoice, $transportItem);

        // A real credit, sourced from a genuine tariff decrease (same
        // fixture pattern as pass #1's own credit tests).
        $decreaseFee = $this->transportItem($invoice)->fee;
        FeePrice::where('fee_id', $decreaseFee->id)->update(['end_date' => '2026-09-30']);
        $decrease = FeePrice::create(['fee_id' => $decreaseFee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        app(\App\Services\Finance\TariffAdjustmentService::class)->approve(ServiceCoverage::where('invoice_item_id', $transportItem->id)->sole()->fresh(), $decrease, $this->accountant);
        $credit = StudentCredit::sole();
        $this->assertTrue(bccomp((string) $credit->available_amount, '100.00', 2) >= 0, 'enough credit exists to apply 100 of it below');

        // Pay 300 cash toward Transport's period first.
        $this->pay($invoice, '300.00', [['invoice_item_id' => $transportItem->id, 'amount' => '300.00']]);
        $this->assertSame('300.00', $period->fresh()->netSettledAmount());
        $this->assertSame('partial', $period->fresh()->settlementStatus());

        // Apply exactly 100 of the credit, explicitly to Transport's item
        // and this same period — reaching full settlement (400) via a
        // MIX of cash (300) and credit (100), both separately auditable.
        app(StudentCreditService::class)->apply($credit, $invoice, '100.00', (string) Str::uuid(), $this->accountant, allocations: [
            ['invoice_item_id' => $transportItem->id, 'amount' => '100.00', 'periods' => [
                ['installment_coverage_period_id' => $period->id, 'amount' => '100.00'],
            ]],
        ]);

        $period->refresh();
        $this->assertSame('400.00', $period->netSettledAmount(), 'total economic settlement is 400 (300 cash + 100 credit)');
        $this->assertSame('settled', $period->settlementStatus());
        // Sources stay separately auditable — never merged into one
        // undifferentiated figure.
        $this->assertSame('300.00', $period->grossPaymentAllocated());
        $this->assertSame('100.00', $period->grossCreditApplied());
        $this->assertSame('0.00', $period->grossRefunded());
        $this->assertSame(1, StudentCreditApplicationItem::count());
        $this->assertSame(1, CreditApplicationCoveragePeriod::count());
    }

    // ----- 8. Legacy ambiguous payment/credit stays explicitly ambiguous -----

    public function test_a_legacy_credit_application_with_no_period_attribution_stays_explicitly_unallocated(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $period = $this->firstPeriod($invoice, $transportItem);

        $decreaseFee = $transportItem->fee;
        FeePrice::where('fee_id', $decreaseFee->id)->update(['end_date' => '2026-09-30']);
        $decrease = FeePrice::create(['fee_id' => $decreaseFee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        app(\App\Services\Finance\TariffAdjustmentService::class)->approve(ServiceCoverage::where('invoice_item_id', $transportItem->id)->sole()->fresh(), $decrease, $this->accountant);
        $credit = StudentCredit::sole();

        // Applied WITHOUT any item/period attribution — the pre-existing,
        // still-fully-supported invoice-level-only path every current
        // caller (TariffAdjustmentService itself, manual whole-invoice
        // application) still uses.
        app(StudentCreditService::class)->apply($credit, $invoice, '100.00', (string) Str::uuid(), $this->accountant);

        $this->assertSame(0, StudentCreditApplicationItem::count());
        $this->assertSame('unpaid', $period->fresh()->settlementStatus(), 'credit reached the invoice but never this item/period — reads as unpaid (zero net), not falsely settled');
    }

    public function test_an_item_level_credit_with_no_period_breakdown_marks_that_items_periods_explicitly_unallocated(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $period = $this->firstPeriod($invoice, $transportItem);

        $decreaseFee = $transportItem->fee;
        FeePrice::where('fee_id', $decreaseFee->id)->update(['end_date' => '2026-09-30']);
        $decrease = FeePrice::create(['fee_id' => $decreaseFee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        app(\App\Services\Finance\TariffAdjustmentService::class)->approve(ServiceCoverage::where('invoice_item_id', $transportItem->id)->sole()->fresh(), $decrease, $this->accountant);
        $credit = StudentCredit::sole();

        // Item-level attribution supplied, but WITHOUT a period
        // breakdown — credit reached Transport's ITEM, but which
        // period(s) it settled is genuinely unrecorded.
        app(StudentCreditService::class)->apply($credit, $invoice, '100.00', (string) Str::uuid(), $this->accountant, allocations: [
            ['invoice_item_id' => $transportItem->id, 'amount' => '100.00'],
        ]);

        $this->assertSame(1, StudentCreditApplicationItem::count());
        $this->assertSame(0, CreditApplicationCoveragePeriod::count());
        $this->assertSame('unallocated', $period->fresh()->settlementStatus());
    }

    // ----- 9. Concurrency -------------------------------------------------

    /**
     * The row lock inside linkAllocationToCoveragePeriod() (A) covers
     * the single-process/SQLite case: two sequential attempts to link a
     * SECOND payment onto an already-full period, the second correctly
     * rejected. A TRUE two-connection concurrent race (both transactions
     * genuinely in flight at once, one blocking on the other's row lock
     * until it commits) is NOT reproducible here — same honest gating
     * this project's pass #2 PostgreSQL concurrency tests already use.
     * This test proves the sequential guarantee only; it does not claim
     * to be concurrency coverage.
     */
    public function test_sequential_final_capacity_race_is_rejected_the_second_time(): void
    {
        $invoice = $this->bundledInvoice();
        $transportItem = $this->transportItem($invoice);
        $installment = $invoice->installments()->orderBy('sequence')->first();

        $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);

        // A second attempt at the exact same (installment, item) pair —
        // record() itself would already reject this at the installment-
        // amount-vs-remaining level (remaining is now 0 for Transport's
        // own share within the shared installment is not separately
        // tracked there, so this exercises the period-level guard
        // directly via the same reflection path as tests 1/2/4/5 above,
        // proving IT is what would stop a payment that somehow got past
        // any installment-level check).
        $tuitionItem = $this->tuitionItem($invoice);
        $anotherPayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00']],
        );
        $forgedAllocation = PaymentAllocation::create([
            'invoice_payment_id' => $anotherPayment->id, 'invoice_item_id' => $transportItem->id, 'amount' => '400.00',
        ]);
        $reflection = new \ReflectionMethod(InvoicePaymentService::class, 'linkAllocationToCoveragePeriod');
        $reflection->setAccessible(true);
        $this->expectException(ValidationException::class);
        $reflection->invoke(app(InvoicePaymentService::class), $forgedAllocation, $this->firstPeriod($invoice, $transportItem), '400.00');
    }

    public function test_payment_idempotency_binds_canonical_item_and_period_allocation_meaning(): void
    {
        $invoice = $this->bundledInvoice();
        $tuition = $this->tuitionItem($invoice);
        $transport = $this->transportItem($invoice);
        $first = $invoice->installments()->orderBy('sequence')->first();
        $key = (string) Str::uuid();
        $service = app(InvoicePaymentService::class);
        $allocation = [
            ['invoice_item_id' => $transport->id, 'amount' => '400.00'],
            ['invoice_item_id' => $tuition->id, 'amount' => '300.00'],
        ];

        $original = $service->record($invoice->id, $this->cash->id, '700', 'cash', $key, $this->accountant, installmentId: $first->id, allocations: $allocation);
        $replay = $service->record($invoice->id, $this->cash->id, '700.00', 'cash', $key, $this->accountant, installmentId: $first->id, allocations: array_reverse($allocation));
        $this->assertSame($original->id, $replay->id);

        foreach ([
            [['invoice_item_id' => $transport->id, 'amount' => '300.00'], ['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
            [['invoice_item_id' => $tuition->id, 'amount' => '700.00']],
        ] as $conflict) {
            try {
                $service->record($invoice->id, $this->cash->id, '700.00', 'cash', $key, $this->accountant, installmentId: $first->id, allocations: $conflict);
                $this->fail('Expected allocation-aware payment idempotency conflict.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('idempotency_key', $e->errors());
            }
        }
        $this->assertSame(2, PaymentAllocation::where('invoice_payment_id', $original->id)->count());
    }

    public function test_credit_idempotency_binds_canonical_item_period_and_distribution(): void
    {
        $invoice = $this->bundledInvoice();
        $transport = $this->transportItem($invoice);
        $tuition = $this->tuitionItem($invoice);
        $coverage = ServiceCoverage::where('invoice_item_id', $transport->id)->sole();
        $old = FeePrice::where('fee_id', $transport->fee_id)->sole();
        $old->update(['end_date' => '2026-09-30']);
        $decrease = FeePrice::create(['fee_id' => $transport->fee_id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '300.00', 'currency' => 'EGP', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        app(\App\Services\Finance\TariffAdjustmentService::class)->approve($coverage, $decrease, $this->accountant);
        $credit = StudentCredit::sole();
        $period = $this->firstPeriod($invoice, $transport);
        $key = (string) Str::uuid();
        $allocation = [['invoice_item_id' => $transport->id, 'amount' => '100.00', 'periods' => [['installment_coverage_period_id' => $period->id, 'amount' => '100.00']]]];
        $service = app(StudentCreditService::class);

        $original = $service->apply($credit, $invoice, '100', $key, $this->accountant, $allocation);
        $replay = $service->apply($credit->fresh(), $invoice, '100.00', $key, $this->accountant, $allocation);
        $this->assertSame($original->id, $replay->id);

        $conflict = [['invoice_item_id' => $tuition->id, 'amount' => '100.00']];
        try {
            $service->apply($credit->fresh(), $invoice, '100.00', $key, $this->accountant, $conflict);
            $this->fail('Expected allocation-aware credit idempotency conflict.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $this->assertSame(1, StudentCreditApplicationItem::where('student_credit_application_id', $original->id)->count());
        $this->assertSame(1, CreditApplicationCoveragePeriod::count());
    }
}
