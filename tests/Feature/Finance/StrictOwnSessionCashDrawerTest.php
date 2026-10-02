<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\User;
use App\Services\Finance\CashDrawerResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PR 4 of the frozen Finance corrective plan — a CASH receipt only ever
 * enters an eligible drawer (active type=cash account, role null or
 * 'operating') through the open CashSession the CURRENT ACTOR opened: 0 own
 * drawers → blocked, 1 → auto-selected, several → the actor chooses one of
 * theirs. Another user's session, owner cash, bank and Instapay are never
 * used for cash; non-cash methods need no session.
 *
 * The shared fixture gives $this->accountant an open session on the
 * operating drawer ($this->cash / $this->cashSession).
 */
class StrictOwnSessionCashDrawerTest extends FinanceOperationsTestCase
{
    private function resolver(): CashDrawerResolver
    {
        return app(CashDrawerResolver::class);
    }

    private function drawer(string $name = 'Касса №2', array $attributes = []): CashAccount
    {
        return CashAccount::create(array_merge(['name' => $name, 'type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => true], $attributes));
    }

    private function rawOpenSession(CashAccount $account, User $opener): CashSession
    {
        return CashSession::create([
            'cash_account_id' => $account->id, 'opened_by' => $opener->id, 'opened_at' => now(),
            'opening_expected' => '0.00', 'opening_expected_source' => CashSession::SOURCE_ACCOUNT_BALANCE,
            'status' => CashSession::STATUS_OPEN,
        ]);
    }

    /** The drawer is held by another cashier; the accountant has no shift. */
    private function handDrawerToAnotherCashier(): User
    {
        $this->closeCashSession();
        $cashier = $this->user('cashier');
        $this->openCashSession($this->cash, $cashier);

        return $cashier;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'invoices' => Invoice::count(),
            'invoice_payments' => InvoicePayment::count(),
            'payment_allocations' => DB::table('payment_allocations')->count(),
            'cash_transactions' => CashTransaction::count(),
            'finance_collections' => FinanceCollection::count(),
            'revenue_entries' => RevenueEntry::count(),
            'balance' => (string) $this->cash->fresh()->balance,
        ];
    }

    private function assertOwnSessionTransaction(CashTransaction $transaction, User $actor): void
    {
        $session = CashSession::findOrFail($transaction->cash_session_id);
        $this->assertSame((int) $actor->id, (int) $session->opened_by);
        $this->assertSame(CashSession::STATUS_OPEN, $session->status);
        $this->assertSame((int) $transaction->cash_account_id, (int) $session->cash_account_id);
    }

    private function category(string $code): RevenueCategory
    {
        return RevenueCategory::firstOrCreate(['code' => $code], ['name_ru' => $code, 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function revenuePayload(RevenueCategory $category, array $overrides = []): array
    {
        return array_merge([
            'revenue_category_id' => $category->id, 'amount' => '150.00', 'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'payment_method' => 'cash', 'status' => RevenueEntry::STATUS_POSTED,
        ], $overrides);
    }

    // ----- A. Eligibility -------------------------------------------------

    public function test_eligibility_requires_an_active_cash_drawer_with_the_actors_own_open_session(): void
    {
        $other = $this->user('cashier');
        $owner = CashAccount::owner();
        $bank = CashAccount::bank();
        $instapay = CashAccount::instapay();
        $inactive = $this->drawer('Неактивная', ['is_active' => false]);
        $othersDrawer = $this->drawer('Чужая');
        $closedDrawer = $this->drawer('Закрытая');

        $this->rawOpenSession($owner, $this->accountant);
        $this->rawOpenSession($bank, $this->accountant);
        $this->rawOpenSession($instapay, $this->accountant);
        $this->rawOpenSession($inactive, $this->accountant);
        $this->openCashSession($othersDrawer, $other);
        $this->closeCashSession($this->openCashSession($closedDrawer, $this->accountant));

        // 1. active operating cash + the actor's own open session — the only one.
        $eligible = $this->resolver()->eligibleSessions($this->accountant);
        $this->assertSame([$this->cash->id], $eligible->pluck('cash_account_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($this->cashSession->id, $eligible->sole()->id);

        // 2–6. another actor's session, owner cash, inactive, closed, bank, instapay.
        foreach ([$othersDrawer, $owner, $inactive, $closedDrawer, $bank, $instapay] as $account) {
            try {
                $this->resolver()->resolve($this->accountant, $account->id);
                $this->fail("Account {$account->name} must not be eligible.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([$othersDrawer->id], $this->resolver()->eligibleSessions($other)->pluck('cash_account_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_an_ordinary_extra_drawer_with_the_actors_own_session_is_eligible(): void
    {
        $second = $this->drawer();
        $session = $this->openCashSession($second, $this->accountant);

        $this->assertSame($session->id, $this->resolver()->resolve($this->accountant, $second->id)->id);
    }

    // ----- B. 0 / 1 / many --------------------------------------------------

    public function test_zero_one_and_many_own_drawers(): void
    {
        // 1 → auto-selected with its own session.
        $this->assertSame($this->cashSession->id, $this->resolver()->resolve($this->accountant, null)->id);

        // many → never first(): no submission is rejected, a submitted own drawer wins.
        $second = $this->drawer();
        $secondSession = $this->openCashSession($second, $this->accountant);
        try {
            $this->resolver()->resolve($this->accountant, null);
            $this->fail('Several own drawers must require an explicit choice.');
        } catch (ValidationException $exception) {
            $this->assertSame([CashDrawerResolver::SEVERAL_OWN_SESSIONS], $exception->errors()['cash_account_id']);
        }
        $this->assertSame($secondSession->id, $this->resolver()->resolve($this->accountant, $second->id)->id);
        $this->assertSame($this->cashSession->id, $this->resolver()->resolve($this->accountant, $this->cash->id)->id);

        // 0 → blocked.
        $this->closeCashSession();
        $this->closeCashSession($secondSession);
        try {
            $this->resolver()->resolve($this->accountant, null);
            $this->fail('No own drawer must block the cash receipt.');
        } catch (ValidationException $exception) {
            $this->assertSame([CashDrawerResolver::NO_OWN_SESSION], $exception->errors()['cash_account_id']);
        }
    }

    public function test_zero_own_drawers_rejects_a_cash_receipt_with_zero_writes(): void
    {
        $this->closeCashSession();
        $invoice = $this->invoice();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_two_own_drawers_with_a_valid_selection_post_to_the_selected_drawer_and_session(): void
    {
        $second = $this->drawer();
        $secondSession = $this->openCashSession($second, $this->accountant);
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $second->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $transaction = CashTransaction::sole();
        $this->assertSame($second->id, (int) $transaction->cash_account_id);
        $this->assertSame($secondSession->id, (int) $transaction->cash_session_id);
        $this->assertOwnSessionTransaction($transaction, $this->accountant);
    }

    public function test_two_own_drawers_without_a_selection_are_rejected_with_zero_writes(): void
    {
        $this->openCashSession($this->drawer(), $this->accountant);
        $invoice = $this->invoice();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash',
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => '100.00']],
        ])->assertSessionHasErrors(['payment_method' => CashDrawerResolver::SEVERAL_OWN_SESSIONS]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_submitting_another_users_drawer_or_owner_cash_is_rejected_with_zero_writes(): void
    {
        $othersDrawer = $this->drawer('Чужая');
        $this->openCashSession($othersDrawer, $this->user('cashier'));
        $invoice = $this->invoice();

        foreach ([$othersDrawer, CashAccount::owner()] as $account) {
            $before = $this->snapshot();
            $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
                'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasErrors();
            $this->assertSame($before, $this->snapshot(), $account->name);
        }
        $this->assertSame(0, CashTransaction::where('cash_account_id', CashAccount::owner()->id)->count());
    }

    // ----- C. Staleness / anomalies ----------------------------------------

    public function test_a_session_closed_or_an_account_deactivated_after_the_form_rendered_is_rejected_with_zero_writes(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice))
            ->assertOk()->assertSee('<option value="'.$this->cash->id.'" data-cash-drawer="1">', false);

        // 14. session closed between render and submit.
        $this->closeCashSession();
        $before = $this->snapshot();
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());

        // 15. account deactivated between render and submit.
        $this->openCashSession($this->cash, $this->accountant);
        $this->cash->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_two_open_sessions_on_one_drawer_are_never_resolved_by_picking_one(): void
    {
        // Not reachable through CashSessionService::open(); a raw anomaly.
        $this->rawOpenSession($this->cash, $this->accountant);
        $invoice = $this->invoice();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors(['payment_method' => CashDrawerResolver::AMBIGUOUS_SESSIONS]);

        $this->assertSame($before, $this->snapshot());
    }

    // ----- D. Non-cash ------------------------------------------------------

    public function test_bank_and_instapay_payments_need_no_cash_session(): void
    {
        $this->closeCashSession();

        foreach (['bank' => CashAccount::bank(), 'instapay' => CashAccount::instapay()] as $method => $account) {
            $invoice = $this->invoice();
            $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
                'amount' => '100.00', 'payment_method' => $method,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

            $transaction = CashTransaction::where('cash_account_id', $account->id)->sole();
            $this->assertNull($transaction->cash_session_id, $method);
        }
    }

    // ----- E. Entry points: another user's session is never used ------------

    public function test_unified_collection_uses_only_the_actors_own_session(): void
    {
        $invoice = $this->invoice();
        $this->handDrawerToAnotherCashier();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash',
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => '100.00']],
        ])->assertSessionHasErrors(['payment_method' => CashDrawerResolver::NO_OWN_SESSION]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invoice_payment_page_uses_only_the_actors_own_session(): void
    {
        $invoice = $this->invoice();
        $cashier = $this->handDrawerToAnotherCashier();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('payment_method');
        $this->assertSame($before, $this->snapshot());

        // The cashier who opened the shift can collect into it.
        $this->actingAs($cashier)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertOwnSessionTransaction(CashTransaction::sole(), $cashier);
    }

    public function test_legacy_classic_invoice_initial_payment_uses_only_the_actors_own_session(): void
    {
        $fee = Fee::create(['name_ru' => 'Продлёнка', 'amount' => '1000.00', 'category' => Fee::CATEGORY_OTHER, 'is_active' => true]);
        $payload = fn () => [
            'idempotency_key' => (string) Str::uuid(), 'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id, 'due_date' => '2026-09-01', 'pricing_date' => '2026-09-01',
            'fees' => [$fee->id], 'cash_account_id' => $this->cash->id,
            'initial_payment_amount' => '400.00', 'payment_method' => 'cash',
        ];
        $this->handDrawerToAnotherCashier();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), $payload())
            ->assertSessionHasErrors('payment_method');
        $this->assertSame($before, $this->snapshot());

        $this->closeCashSession(CashSession::where('status', CashSession::STATUS_OPEN)->sole());
        $own = $this->openCashSession($this->cash, $this->accountant);
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), $payload())->assertSessionHasNoErrors();
        $this->assertSame($own->id, (int) CashTransaction::sole()->cash_session_id);
    }

    public function test_charge_and_collect_uses_only_the_actors_own_session(): void
    {
        $fee = $this->makeAdditionalServiceFee();
        $payload = fn () => [
            'academic_year_id' => $this->year->id, 'fee_id' => $fee->id, 'quantity' => 1,
            'payment_period' => 'yearly', 'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '500.00', 'payment_method' => 'cash',
        ];
        $cashier = $this->handDrawerToAnotherCashier();
        $before = $this->snapshot();

        $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), $payload())
            ->assertSessionHasErrors('payment_method');
        $this->assertSame($before, $this->snapshot());

        $this->actingAs($cashier)->post(route('dashboard.students.charge.store', $this->student), $payload())
            ->assertSessionHasNoErrors();
        $this->assertOwnSessionTransaction(CashTransaction::sole(), $cashier);
    }

    public function test_generic_buffet_and_donation_revenue_post_only_through_the_actors_own_session(): void
    {
        $cases = [
            'other' => [$this->category(RevenueCategory::CODE_OTHER), []],
            'buffet' => [$this->category(RevenueCategory::CODE_BUFFET), ['type' => 'buffet']],
            'donation' => [$this->category(RevenueCategory::CODE_DONATION), ['type' => 'donation']],
        ];
        $this->handDrawerToAnotherCashier();

        foreach ($cases as $label => [$category, $extra]) {
            $before = $this->snapshot();
            $this->actingAs($this->accountant)
                ->post(route('dashboard.finance.income.revenue.store'), $this->revenuePayload($category, $extra))
                ->assertSessionHasErrors('cash_account_id');
            $this->assertSame($before, $this->snapshot(), $label);
        }

        $this->closeCashSession(CashSession::where('status', CashSession::STATUS_OPEN)->sole());
        $this->openCashSession($this->cash, $this->accountant);
        foreach ($cases as $label => [$category, $extra]) {
            $this->actingAs($this->accountant)
                ->post(route('dashboard.finance.income.revenue.store'), $this->revenuePayload($category, $extra))
                ->assertSessionHasNoErrors();
            $entry = RevenueEntry::where('revenue_category_id', $category->id)->sole();
            $this->assertOwnSessionTransaction($entry->cashTransaction, $this->accountant);
        }
    }

    public function test_cash_revenue_cannot_be_posted_into_bank_or_owner_cash(): void
    {
        $other = $this->category(RevenueCategory::CODE_OTHER);

        foreach ([CashAccount::bank(), CashAccount::owner()] as $account) {
            $before = $this->snapshot();
            $this->actingAs($this->accountant)
                ->post(route('dashboard.finance.income.revenue.store'), $this->revenuePayload($other, ['cash_account_id' => $account->id]))
                ->assertSessionHasErrors('cash_account_id');
            $this->assertSame($before, $this->snapshot(), $account->name);
        }

        // A genuinely non-cash revenue into the bank needs no session.
        $this->closeCashSession();
        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.income.revenue.store'), $this->revenuePayload($other, ['cash_account_id' => CashAccount::bank()->id, 'payment_method' => 'bank']))
            ->assertSessionHasNoErrors();
        $this->assertNull(RevenueEntry::sole()->cashTransaction->cash_session_id);
    }

    public function test_a_revenue_draft_needs_no_session_but_is_posted_only_through_the_posters_own_session(): void
    {
        $this->closeCashSession();
        $other = $this->category(RevenueCategory::CODE_OTHER);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.income.revenue.store'), $this->revenuePayload($other, ['status' => RevenueEntry::STATUS_DRAFT]))
            ->assertSessionHasNoErrors();
        $draft = RevenueEntry::sole();
        $this->assertTrue($draft->isDraft());

        $this->openCashSession($this->cash, $this->user('cashier'));
        $before = $this->snapshot();
        $this->actingAs($this->accountant)->post(route('dashboard.finance.income.revenue.post', $draft))->assertSessionHasErrors('cash_account_id');
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($draft->fresh()->isDraft());
    }
}
