<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Services\Finance\CashSessionService;
use Illuminate\Support\Str;

/**
 * Finance V2 — characterizes every live payment-recording entry point
 * against the pre-implementation compatibility audit
 * (docs/finance-v2-architecture.md §19 Phase 1A/1B table).
 *
 * Phase 1A: proved the additive allocation foundation broke nothing, and
 * that every caller could still create an unallocated payment against a
 * multi-item invoice (the gap Phase 1B was scoped to close).
 *
 * Phase 1B: Classic Invoice and the existing-invoice later-payment screen
 * were both given explicit per-item allocation UI, and their controllers
 * now build and require an allocations array whenever the target invoice
 * is multi-item and allocation-clean (see InvoicePaymentService::
 * isAllocationClean()). Submitting without allocations in that situation
 * is now rejected instead of silently succeeding unallocated — the two
 * "still_succeeds_unallocated" tests below were rewritten to characterize
 * that intentional behavior change; new tests alongside them prove the
 * explicit-allocation path succeeds correctly.
 *
 * Confirmed this cycle while writing these tests: Charge & Collect
 * (StoreChargeAndCollectRequest::prepareForValidation()) always builds a
 * single-element items array from its top-level fee_id/quantity fields —
 * it is structurally single-item only today, unlike Classic Invoice, which
 * genuinely accepts multiple selected fees (fees[]) in one submission. It
 * is therefore never affected by the Phase 1B change (Phase 1A's
 * deterministic single-item auto-allocation already covers it).
 */
class PaymentAllocationEntryPointCharacterizationTest extends MassBillingTestCase
{
    private function registrationFee(string $amount = '500.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    public function test_classic_invoice_with_multiple_fees_and_immediate_payment_without_allocations_is_rejected(): void
    {
        $student = $this->enrolledStudent(suffix: 'ClassicMulti');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition; // already priced 1200.00 for $this->grade in MassBillingTestCase::setUp()

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
            'payment_method' => 'cash', 'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'initial_payment_amount' => '900.00',
            // No `allocations` submitted — Phase 1B now requires it for a
            // multi-item invoice with an immediate payment.
        ]);

        // Finance V2, Phase 1B — a brand-new invoice is always
        // allocation-clean, so its multi-item immediate payment must now be
        // explicitly split; an omitted split is rejected rather than
        // guessed or silently left unallocated.
        $response->assertSessionHasErrors('allocations');

        // The whole issue-and-pay operation is one DB transaction
        // (InvoiceController::store()) — rejecting the payment must not
        // leave a half-issued invoice behind either.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_classic_invoice_with_multiple_fees_and_explicit_allocations_succeeds(): void
    {
        $student = $this->enrolledStudent(suffix: 'ClassicMultiAlloc');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition;

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
            'payment_method' => 'cash', 'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'initial_payment_amount' => '900.00',
            'allocations' => [$feeA->id => '500.00', $feeB->id => '400.00'],
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();
        $this->assertSame('900.00', (string) $payment->amount);

        $itemA = $invoice->items()->where('fee_id', $feeA->id)->sole();
        $itemB = $invoice->items()->where('fee_id', $feeB->id)->sole();
        $this->assertSame(2, PaymentAllocation::count());
        $this->assertSame('500.00', (string) PaymentAllocation::where('invoice_item_id', $itemA->id)->sole()->amount);
        $this->assertSame('400.00', (string) PaymentAllocation::where('invoice_item_id', $itemB->id)->sole()->amount);
    }

    public function test_classic_invoice_single_fee_still_requires_no_manual_split(): void
    {
        $student = $this->enrolledStudent(suffix: 'ClassicSingle');
        $fee = $this->registrationFee('500.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$fee->id],
            'payment_method' => 'cash', 'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'initial_payment_amount' => '500.00',
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($invoice->items->first()->id, PaymentAllocation::sole()->invoice_item_id);
    }

    public function test_charge_and_collect_is_structurally_single_item_and_auto_allocates(): void
    {
        $student = $this->enrolledStudent(suffix: 'ChargeSingle');
        $fee = $this->registrationFee('500.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $fee->id, 'quantity' => 1,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'collect_amount' => '500.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(1, $invoice->items()->count(), 'Charge & Collect always builds exactly one item — confirmed via StoreChargeAndCollectRequest::prepareForValidation()');
        $payment = InvoicePayment::sole();
        // Single-item invoice — Phase 1A's deterministic auto-allocation
        // applies even though this caller was never updated to pass
        // allocations explicitly.
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($invoice->items->first()->id, PaymentAllocation::sole()->invoice_item_id);
        $this->assertSame('500.00', (string) PaymentAllocation::sole()->amount);
    }

    public function test_existing_invoice_later_payment_against_a_multi_item_clean_invoice_without_allocations_is_rejected(): void
    {
        $student = $this->enrolledStudent(suffix: 'LaterPay');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition;

        // Issue a multi-item invoice with no initial payment — clean by
        // construction (zero prior payments).
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertGreaterThanOrEqual(2, $invoice->items()->count());

        // A later payment against it, exactly like FinanceOperationsController::storePayment(),
        // with no allocations submitted.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1000.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_existing_invoice_later_payment_against_a_multi_item_clean_invoice_with_explicit_allocations_succeeds(): void
    {
        $student = $this->enrolledStudent(suffix: 'LaterPayAlloc');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition;

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $itemA = $invoice->items()->where('fee_id', $feeA->id)->sole();
        $itemB = $invoice->items()->where('fee_id', $feeB->id)->sole();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1000.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '500.00', $itemB->id => '500.00'],
        ]);
        $response->assertSessionHasNoErrors();

        $payment = InvoicePayment::sole();
        $this->assertSame('1000.00', (string) $payment->amount);
        $this->assertSame(2, PaymentAllocation::count());
        $this->assertSame('500.00', (string) PaymentAllocation::where('invoice_item_id', $itemA->id)->sole()->amount);
        $this->assertSame('500.00', (string) PaymentAllocation::where('invoice_item_id', $itemB->id)->sole()->amount);
    }

    /** The canonical cash-drawer account, with an open session for cash-payment tests. */
    private function cashAccountForInvoicePayments(): CashAccount
    {
        $account = CashAccount::operating();
        if (! app(CashSessionService::class)->activeFor($account)) {
            app(CashSessionService::class)->open($account, $this->accountant);
        }

        return $account;
    }
}
