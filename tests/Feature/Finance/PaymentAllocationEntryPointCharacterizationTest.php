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
 * Finance V2, Phase 1A — characterizes every live payment-recording entry
 * point against the pre-implementation compatibility audit
 * (docs/finance-v2-architecture.md §19 Phase 1A/1B table), proving none was
 * broken by the additive allocation foundation.
 *
 * Confirmed this cycle while writing these tests: Charge & Collect
 * (StoreChargeAndCollectRequest::prepareForValidation()) always builds a
 * single-element items array from its top-level fee_id/quantity fields —
 * it is structurally single-item only today, unlike Classic Invoice, which
 * genuinely accepts multiple selected fees (fees[]) in one submission. Both
 * are exercised here so the record reflects what was actually verified,
 * not the more general "may be multi-item" assumption the initial audit
 * made before this closer read.
 */
class PaymentAllocationEntryPointCharacterizationTest extends MassBillingTestCase
{
    private function registrationFee(string $amount = '500.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    public function test_classic_invoice_with_multiple_fees_and_immediate_payment_still_succeeds_unallocated(): void
    {
        $student = $this->enrolledStudent(suffix: 'ClassicMulti');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition; // already priced 1200.00 for $this->grade in MassBillingTestCase::setUp()

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
            'payment_method' => 'cash', 'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'initial_payment_amount' => '900.00',
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('1700.00', $invoice->total_amount);
        $this->assertGreaterThanOrEqual(2, $invoice->items()->count(), 'this invoice genuinely has multiple InvoiceItems');

        $payment = InvoicePayment::sole();
        $this->assertSame('900.00', (string) $payment->amount);
        // Phase 1A's intentional, temporary compatibility state — no
        // allocation UI exists yet for Classic Invoice (that is Phase 1B),
        // so this legacy multi-item submission must succeed unallocated,
        // never guessed, never rejected.
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
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

    public function test_existing_invoice_later_payment_against_a_multi_item_invoice_still_succeeds_unallocated(): void
    {
        $student = $this->enrolledStudent(suffix: 'LaterPay');
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition;

        // Issue a multi-item invoice with no initial payment.
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertGreaterThanOrEqual(2, $invoice->items()->count());

        // A later payment against it, exactly like FinanceOperationsController::storePayment().
        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1000.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertSessionHasNoErrors();

        $payment = InvoicePayment::sole();
        $this->assertSame('1000.00', (string) $payment->amount);
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
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
