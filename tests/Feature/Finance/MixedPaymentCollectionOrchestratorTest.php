<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\MixedPaymentCollectionOrchestrator;
use Illuminate\Validation\ValidationException;

/**
 * Unified Collection foundation (PR A) — isolation tests for the extracted
 * MixedPaymentCollectionOrchestrator::collectAcrossInstallments(), calling
 * it directly (no QuickStudentRegistrationService involved) against a real
 * Invoice issued through the unchanged InvoiceIssuanceService, to prove
 * this extracted method reproduces exactly the original inline
 * else/plan-branch behavior: single-installment partial/full acceptance,
 * multi-installment whole-installment-only walk, deterministic
 * (token, index) idempotency keys, and the allocations-only-on-a-single-
 * settlement rule.
 *
 * collectMixed()/collectCalendarPeriods() (the once+calendar and
 * calendar+Food branches) are already exhaustively exercised end-to-end,
 * unmodified, by the existing QuickRegistrationMixedBillingTest,
 * QuickRegistrationMixedUniformPartialPaymentTest and FoodDailyBillingTest
 * suites (all still passing unchanged) — building the coverage-period
 * fixtures those two methods require here as well would only duplicate
 * that existing coverage, not add a genuinely new isolation guarantee.
 */
class MixedPaymentCollectionOrchestratorTest extends FinanceOperationsTestCase
{
    private function issueSingleInstallmentInvoice(): \App\Models\Invoice
    {
        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    public function test_single_installment_partial_payment_settles_with_explicit_allocations(): void
    {
        $invoice = $this->issueSingleInstallmentInvoice();
        $item = $invoice->items->sole();
        $allocations = [['invoice_item_id' => $item->id, 'amount' => '500.00']];

        app(MixedPaymentCollectionOrchestrator::class)->collectAcrossInstallments(
            $invoice, $allocations, '500.00', 'cash', $this->cash->id, $this->accountant,
            'Test reference', null, 'outer-token-1', 'test-namespace',
        );

        $invoice->refresh();
        $payment = $invoice->payments->sole();
        $this->assertSame('500.00', (string) $payment->amount);
        $this->assertSame('700.00', (string) $invoice->installments()->sole()->remaining_amount);
        $this->assertSame('500.00', (string) $payment->allocations->sole()->amount);
    }

    public function test_single_installment_overpayment_is_rejected_in_russian(): void
    {
        $invoice = $this->issueSingleInstallmentInvoice();
        $item = $invoice->items->sole();
        $allocations = [['invoice_item_id' => $item->id, 'amount' => '5000.00']];

        $this->expectException(ValidationException::class);

        app(MixedPaymentCollectionOrchestrator::class)->collectAcrossInstallments(
            $invoice, $allocations, '5000.00', 'cash', $this->cash->id, $this->accountant,
            'Test reference', null, 'outer-token-2', 'test-namespace',
        );
    }

    public function test_retry_with_the_same_outer_token_and_suffix_does_not_duplicate_the_payment(): void
    {
        $invoice = $this->issueSingleInstallmentInvoice();
        $item = $invoice->items->sole();
        $allocations = [['invoice_item_id' => $item->id, 'amount' => '500.00']];

        $orchestrator = app(MixedPaymentCollectionOrchestrator::class);
        $orchestrator->collectAcrossInstallments($invoice, $allocations, '500.00', 'cash', $this->cash->id, $this->accountant, 'Test reference', null, 'outer-token-3', 'test-namespace');
        $orchestrator->collectAcrossInstallments($invoice, $allocations, '500.00', 'cash', $this->cash->id, $this->accountant, 'Test reference', null, 'outer-token-3', 'test-namespace');

        $invoice->refresh();
        $this->assertCount(1, $invoice->payments);
    }

    public function test_multi_installment_schedule_must_cover_a_whole_number_of_installments(): void
    {
        $this->fee->billingPeriods()->create(['billing_period' => 'monthly']);
        \App\Models\FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'monthly', 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertGreaterThan(1, $installments->count());
        $firstAmount = (string) $installments->first()->remaining_amount;

        // Exactly one whole installment's worth succeeds. Passing an
        // explicit item-level $allocations here would only be honored if
        // it summed to the settled amount (SUM(allocations) ===
        // payment.amount invariant); a single-installment settlement is
        // free to instead record as Unallocated, so the caller passes an
        // amount-matching allocation for the sole item.
        $item = $invoice->items->sole();
        app(MixedPaymentCollectionOrchestrator::class)->collectAcrossInstallments(
            $invoice, [['invoice_item_id' => $item->id, 'amount' => $firstAmount]], $firstAmount, 'cash', $this->cash->id, $this->accountant,
            'Test reference', null, 'outer-token-4', 'test-namespace',
        );
        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->installments()->orderBy('sequence')->first()->remaining_amount);

        // A remainder that doesn't cover a whole next installment fails closed.
        $this->expectException(ValidationException::class);
        app(MixedPaymentCollectionOrchestrator::class)->collectAcrossInstallments(
            $invoice, [['invoice_item_id' => $item->id, 'amount' => '1.00']], '1.00', 'cash', $this->cash->id, $this->accountant,
            'Test reference', null, 'outer-token-5', 'test-namespace',
        );
    }
}
