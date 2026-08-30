<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Phase 0 — Safety Lockdown regression tests.
 *
 * Proves that every legacy/unsafe finance write path is disabled or delegates
 * safely, that permission gates are in place, and that the canonical payment
 * and cash behaviour is unchanged.
 */
class Phase0SafetyLockdownTest extends FinanceOperationsTestCase
{
    // ----- Legacy/unsafe write paths are disabled (410) -------------------

    public function test_legacy_invoice_refund_endpoint_is_disabled(): void
    {
        $invoice = $this->invoice();

        // Accountant holds 'manage invoices', so the request passes the gate
        // and reaches the (now disabled) action.
        $this->actingAs($this->accountant)
            ->post(route('dashboard.invoices.refund', $invoice))
            ->assertStatus(410);
    }

    public function test_raw_cash_income_endpoint_is_disabled(): void
    {
        $admin = $this->user('admin'); // holds 'manage cash'

        $this->actingAs($admin)
            ->post(route('dashboard.cash.income.store'), [
                'cash_account_id' => $this->cash->id,
                'amount' => '500.00',
            ])
            ->assertStatus(410);

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_raw_cash_expense_endpoint_is_disabled(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('dashboard.cash.expenses.store'), [
                'cash_account_id' => $this->cash->id,
                'amount' => '500.00',
            ])
            ->assertStatus(410);

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_legacy_cash_account_transfer_endpoint_is_disabled(): void
    {
        $admin = $this->user('admin');
        $other = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('dashboard.cash.transfer'), [
                'from_account_id' => $this->cash->id,
                'to_account_id' => $other->id,
                'amount' => '100.00',
            ])
            ->assertStatus(410);
    }

    public function test_legacy_invoice_pay_endpoint_is_disabled(): void
    {
        // Finance V2, Phase 1B.1: InvoiceController::pay() had no reachable
        // UI (dashboard/invoices/pay.blade.php is never served by any GET
        // route), never resolved cash_account_id to its canonical account,
        // and always called InvoicePaymentService::record() unallocated —
        // so it stayed capable of creating an unallocated multi-item
        // payment after Phase 1B closed that gap everywhere reachable from
        // the UI. Retired with the same 410 pattern as the legacy refund
        // endpoint above.
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)
            ->post(route('dashboard.invoices.pay', $invoice), [
                'amount' => '100.00',
                'cash_account_id' => $this->cash->id,
                'payment_method' => 'cash',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(410);

        $this->assertDatabaseCount('invoice_payments', 0);
    }

    public function test_legacy_transport_monthly_invoices_is_disabled(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.transport.monthly.invoices'))
            ->assertStatus(410);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_legacy_debt_receipt_is_disabled(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.debts.receipt', $invoice->id))
            ->assertStatus(410);
    }

    public function test_dead_fee_bulk_assign_route_does_not_exist(): void
    {
        // The unsafe FeeController::assignToStudent/bulkAssign methods are not
        // wired to any route (and are neutralised in code).
        $this->assertFalse(Route::has('dashboard.fees.bulk.assign'));
        $this->assertFalse(Route::has('dashboard.fees.assign'));
    }

    // ----- Permission gates on cash routes --------------------------------

    public function test_cash_income_requires_manage_cash_permission(): void
    {
        // Accountant is administrative but does NOT hold 'manage cash'.
        $this->actingAs($this->accountant)
            ->post(route('dashboard.cash.income.store'), [
                'cash_account_id' => $this->cash->id,
                'amount' => '500.00',
            ])
            ->assertForbidden();
    }

    public function test_cash_reports_require_view_cash_reports_permission(): void
    {
        $this->actingAs($this->accountant) // lacks 'view cash reports'
            ->get(route('dashboard.cash.reports'))
            ->assertForbidden();
    }

    // ----- Canonical / correct behaviour is preserved ---------------------

    public function test_canonical_payment_still_records_and_updates_cash(): void
    {
        $invoice = $this->invoice('1200.00');

        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '1200.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->remaining_amount);
        $this->assertSame('1200.00', (string) $this->cash->fresh()->balance);
        $this->assertDatabaseCount('cash_transactions', 1);
    }

    public function test_expense_now_decrements_the_cash_account_balance(): void
    {
        Expense::create([
            'title' => 'Канцелярия',
            'amount' => '250.00',
            'category' => 'supplies',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
        ]);

        // Previously wrote an invalid type='expense' that the balance hook
        // ignored; now posts a valid outgoing transaction.
        $this->assertSame('-250.00', (string) $this->cash->fresh()->balance);
        $this->assertDatabaseHas('cash_transactions', [
            'cash_account_id' => $this->cash->id,
            'type' => 'out',
            'category' => 'expense',
            'amount' => '250.00',
        ]);
    }
}
