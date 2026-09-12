<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use Illuminate\Support\Str;

/**
 * Cash Operations Phase 4 — canonical payment-method → account mapping.
 *
 * bank and InstaPay money still never lets the employee-facing forms choose
 * where it lands: the server resolves the one canonical account for that
 * method itself and ignores whatever cash_account_id a request submits.
 * This still closes the "tampered account id redirects money" gap for
 * those two methods and keeps the owner's holding account out of the
 * ordinary student-payment path entirely.
 *
 * cash is the one exception, per the Student Payment Final Corrective's
 * explicit business decision: the operator now MUST manually name which
 * physical drawer received the cash (dashboard.invoices.payments.store —
 * FinanceOperationsController::storePayment() — only; every other
 * cash-accepting entry point — charge & collect, quick registration — is
 * untouched and still auto-routes cash to the canonical operating account).
 * An explicitly submitted, eligible (active, CashAccount::TYPE_CASH)
 * account is now honored exactly as submitted; an ineligible one is
 * rejected outright — never silently swapped for the canonical account, and
 * never silently accepted either. See StudentPaymentFinalCorrectiveTest for
 * the full behavior this corrective added.
 */
class CanonicalPaymentAccountMappingTest extends FinanceOperationsTestCase
{
    public function test_cash_payment_with_an_explicit_eligible_account_is_honored_exactly_as_submitted(): void
    {
        $selected = CashAccount::create(['name' => 'Касса кассира', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        app(\App\Services\Finance\CashSessionService::class)->open($selected, $this->accountant);
        $invoice = $this->invoice();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00',
            'cash_account_id' => $selected->id,
            'payment_method' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();
        $this->assertSame($selected->id, $payment->cash_account_id);
        $this->assertNotSame($this->cash->id, $payment->cash_account_id);
        $this->assertSame('500.00', CashTransaction::sole()->amount);
        $this->assertSame($selected->id, CashTransaction::sole()->cash_account_id);
        $this->assertSame('500.00', $selected->fresh()->balance);
        $this->assertSame('0.00', $this->cash->fresh()->balance);
    }

    public function test_cash_payment_without_an_open_session_on_the_selected_drawer_is_rejected(): void
    {
        $noSession = CashAccount::create(['name' => 'Касса без смены', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        // Deliberately no session opened on $noSession.
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00',
            'cash_account_id' => $noSession->id,
            'payment_method' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_bank_payment_maps_to_the_canonical_bank_account_regardless_of_submitted_id(): void
    {
        $bankAccount = CashAccount::bank();
        $this->assertNotNull($bankAccount, 'A canonical bank account must exist after the role-backfill migration.');
        $decoy = CashAccount::create(['name' => 'Другой счёт', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '300.00',
            'cash_account_id' => $decoy->id,
            'payment_method' => 'bank',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($bankAccount->id, InvoicePayment::sole()->cash_account_id);
    }

    public function test_instapay_payment_maps_to_the_canonical_instapay_account(): void
    {
        $instapayAccount = CashAccount::instapay();
        $this->assertNotNull($instapayAccount, 'A canonical InstaPay account must exist after the role-backfill migration.');
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '150.00',
            'payment_method' => 'instapay',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($instapayAccount->id, InvoicePayment::sole()->cash_account_id);
    }

    public function test_owner_account_is_rejected_even_when_directly_submitted_for_a_method_without_canonical_mapping(): void
    {
        $owner = CashAccount::owner();
        $this->assertNotNull($owner, 'A canonical owner account must exist after the role-backfill migration.');
        $invoice = $this->invoice();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00',
            'cash_account_id' => $owner->id,
            'payment_method' => 'card',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('cash_account_id');
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_owner_account_is_excluded_from_the_payment_account_selector(): void
    {
        $owner = CashAccount::owner();
        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.payments.create', $this->invoice()))
            ->assertOk()
            ->assertDontSee($owner->name);
    }

    public function test_owner_account_is_excluded_from_the_charge_and_collect_selector(): void
    {
        $owner = CashAccount::owner();
        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.charge.create', $this->student))
            ->assertOk()
            ->assertDontSee($owner->name);
    }
}
