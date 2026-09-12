<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Student Payment Allocation UX corrective.
 *
 * UX/discoverability + receipt consistency only — every scenario here goes
 * through the exact same canonical entry points and the exact same
 * InvoicePaymentService::record()/isAllocationClean()/
 * remainingAllocatableByItem() contract already proven correct by
 * PaymentAllocationTest, FinanceV2Phase1B/1C/1E*Test, and
 * PaymentAllocationEntryPointCharacterizationTest. Nothing here tests a new
 * allocation algorithm — it proves the UI surfaces the existing one
 * correctly, still lets the backend reject anything unsafe, and never
 * fabricates data the database doesn't actually have.
 */
class StudentPaymentAllocationUxTest extends MassBillingTestCase
{
    // Registration and Books are used here (instead of Transport/Food from
    // the business example) deliberately: both are already proven, in the
    // existing test suite (FinanceV2Phase1BAllocationTest,
    // QuickRegistrationPaymentAllocationTest), to combine with Tuition on
    // one invoice with no extra calendar/duration-selection plumbing.
    // Transport and Food carry their own unrelated billing-strategy
    // validation (calendar payment_type, food duration selection — see
    // InvoiceIssuanceService) that has nothing to do with this corrective's
    // allocation UX and would only add unrelated risk to these tests. The
    // allocation mechanism itself (PaymentAllocation, isAllocationClean(),
    // remainingAllocatableByItem()) is identical regardless of which Fee
    // category an InvoiceItem belongs to.
    private function registrationFee(string $amount = '500.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    private function booksFee(string $amount = '250.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Книги', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    private function cashAccountForInvoicePayments(): CashAccount
    {
        $account = CashAccount::operating();
        if (! app(CashSessionService::class)->activeFor($account)) {
            app(CashSessionService::class)->open($account, $this->accountant);
        }

        return $account;
    }

    /** @return array{0: Invoice, 1: \App\Models\InvoiceItem, 2: \App\Models\InvoiceItem, 3: \App\Models\InvoiceItem} */
    private function issueMixedInvoice(string $suffix): array
    {
        $student = $this->enrolledStudent(suffix: $suffix);
        $registration = $this->registrationFee();
        $books = $this->booksFee();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$this->tuition->id, $registration->id, $books->id],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('student_id', $student->id)->sole();
        $tuitionItem = $invoice->items()->where('fee_id', $this->tuition->id)->sole();
        $registrationItem = $invoice->items()->where('fee_id', $registration->id)->sole();
        $booksItem = $invoice->items()->where('fee_id', $books->id)->sole();

        return [$invoice, $tuitionItem, $registrationItem, $booksItem];
    }

    private function insertLegacyUnallocatedPayment(Invoice $invoice, CashAccount $account, string $amount): InvoicePayment
    {
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'cash_account_id' => $account->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'paid_at' => now(),
            'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'legacy-'.Str::random()),
        ]);
        CashTransaction::create([
            'cash_account_id' => $account->id,
            'created_by' => $this->accountant->id,
            'invoice_payment_id' => $payment->id,
            'amount' => $amount,
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        return $payment;
    }

    // 1. Student search shows service names for mixed invoices.
    public function test_student_search_shows_service_breakdown_for_a_mixed_invoice(): void
    {
        [$invoice] = $this->issueMixedInvoice('SearchBreakdown');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee('Обучение');
        $response->assertSee('Организационный взнос');
        $response->assertSee('Книги');
        $response->assertSee($invoice->display_number);
    }

    // 2. Mixed clean invoice payment form shows item allocation inputs.
    public function test_clean_multi_item_payment_form_shows_per_item_allocation_inputs(): void
    {
        [$invoice, $tuitionItem, $registrationItem, $booksItem] = $this->issueMixedInvoice('CleanForm');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $response->assertSee('name="allocations['.$tuitionItem->id.']"', false);
        $response->assertSee('name="allocations['.$registrationItem->id.']"', false);
        $response->assertSee('name="allocations['.$booksItem->id.']"', false);
        $response->assertDontSee('исторические платежи без полного распределения');
    }

    // 3. "Tuition only" payment persists exactly one PaymentAllocation for Tuition.
    public function test_tuition_only_payment_persists_exactly_one_allocation(): void
    {
        [$invoice, $tuitionItem] = $this->issueMixedInvoice('TuitionOnly');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00'],
        ]);

        $response->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();
        $this->assertSame('1200.00', (string) $payment->amount);
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($tuitionItem->id, PaymentAllocation::sole()->invoice_item_id);
        $this->assertSame('1200.00', (string) PaymentAllocation::sole()->amount);
    }

    // 4. "Tuition + one other service, not the third" persists exactly two
    //    correct allocations (the mixed-invoice pattern from the business
    //    example — Tuition+Transport paid, Food unpaid — proven here with
    //    Registration/Books in place of Transport/Food; see the class
    //    docblock on registrationFee()/booksFee() for why).
    public function test_tuition_and_second_service_not_third_persists_exactly_two_allocations(): void
    {
        [$invoice, $tuitionItem, $registrationItem, $booksItem] = $this->issueMixedInvoice('TuitionTransport');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1700.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00', $registrationItem->id => '500.00'],
        ]);

        $response->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();
        $this->assertSame('1700.00', (string) $payment->amount);
        $this->assertSame(2, PaymentAllocation::count());
        $this->assertSame('1200.00', (string) PaymentAllocation::where('invoice_item_id', $tuitionItem->id)->sole()->amount);
        $this->assertSame('500.00', (string) PaymentAllocation::where('invoice_item_id', $registrationItem->id)->sole()->amount);
        $this->assertFalse(PaymentAllocation::where('invoice_item_id', $booksItem->id)->exists(), 'The third, unpaid service must receive no allocation row.');
    }

    // 5. Allocation amounts must sum to payment amount.
    public function test_allocation_sum_mismatch_is_rejected(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoice('SumMismatch');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1700.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            // Sums to 1600.00, not the submitted 1700.00 amount.
            'allocations' => [$tuitionItem->id => '1200.00', $registrationItem->id => '400.00'],
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    // 6. Per-item allocation cannot exceed canonical remaining allocatable amount.
    public function test_per_item_allocation_cannot_exceed_remaining_allocatable_amount(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoice('OverCap');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            // Tuition's own line is 1200.00 — asking to allocate 1300.00 to
            // it must be rejected even though the total payment amount
            // itself is otherwise within the invoice's remaining balance.
            'amount' => '1300.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1300.00'],
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    // 7. Single-item invoice remains auto-allocated correctly.
    public function test_single_item_invoice_still_auto_allocates_with_no_ui_allocation_inputs(): void
    {
        $student = $this->enrolledStudent(suffix: 'SingleItem');
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$this->tuition->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::where('student_id', $student->id)->sole();

        $formResponse = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));
        $formResponse->assertOk();
        $formResponse->assertDontSee('name="allocations[', false);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($invoice->items->first()->id, PaymentAllocation::sole()->invoice_item_id);
    }

    // 8. Ambiguous invoice does NOT expose misleading service-selection UX.
    public function test_ambiguous_invoice_does_not_expose_allocation_inputs(): void
    {
        [$invoice, $tuitionItem, $registrationItem, $booksItem] = $this->issueMixedInvoice('Ambiguous');
        $account = $this->cashAccountForInvoicePayments();
        $this->insertLegacyUnallocatedPayment($invoice, $account, '1000.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $response->assertDontSee('name="allocations[', false);
        $response->assertSee('исторические платежи без полного распределения');
        // Read-only informational breakdown must still be visible.
        $response->assertSee('Обучение');
        $response->assertSee('Организационный взнос');
        $response->assertSee('Книги');
    }

    // 9. Ambiguous invoice payment behavior remains invoice-level per current backend semantics.
    public function test_ambiguous_invoice_payment_remains_invoice_level_with_zero_allocations(): void
    {
        [$invoice] = $this->issueMixedInvoice('AmbiguousPay');
        $account = $this->cashAccountForInvoicePayments();
        $this->insertLegacyUnallocatedPayment($invoice, $account, '800.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '700.00', 'payment_method' => 'cash',
            'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $newPayment = InvoicePayment::where('amount', '700.00')->sole();
        $this->assertSame(0, PaymentAllocation::where('invoice_payment_id', $newPayment->id)->count());
    }

    // 10. Receipt shows exact service allocation when allocations exist. 12. Receipt is read-only (GET).
    public function test_receipt_shows_service_allocation_breakdown_when_allocations_exist(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoice('ReceiptBreakdown');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1700.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00', $registrationItem->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertSee('Оплачено по услугам');
        $response->assertSee('Обучение');
        $response->assertSee('Организационный взнос');
        $response->assertDontSee('Распределение по услугам отсутствует.');
    }

    // 11. Receipt does NOT fabricate allocation details when none exist.
    public function test_receipt_shows_neutral_note_when_no_allocation_rows_exist(): void
    {
        [$invoice] = $this->issueMixedInvoice('ReceiptNoAlloc');
        $account = $this->cashAccountForInvoicePayments();
        $this->insertLegacyUnallocatedPayment($invoice, $account, '800.00');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '700.00', 'payment_method' => 'cash',
            'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::where('amount', '700.00')->sole();
        $this->assertSame(0, $payment->allocations()->count());

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertSee('Распределение по услугам отсутствует.');
        $response->assertDontSee('Оплачено по услугам');
    }

    // 12. Payment receipt route is read-only (GET only).
    public function test_receipt_route_accepts_only_get(): void
    {
        [$invoice, $tuitionItem] = $this->issueMixedInvoice('ReceiptReadOnly');
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccountForInvoicePayments()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00'],
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.payments.receipt', $payment));

        $response->assertStatus(405);
    }

    // 15. Existing student payment permission behavior is preserved on the search screen.
    public function test_search_screen_hides_pay_action_without_manage_invoices_permission(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        [$invoice] = $this->issueMixedInvoice('PermissionCheck');
        // 'reception' has 'view invoices' but deliberately not 'manage
        // invoices' (RolesAndPermissionsSeeder) — the exact role shape this
        // screen's @can('manage invoices') gate is meant to enforce.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('reception');

        $response = $this->actingAs($viewer)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertDontSee('Оплатить');
    }
}
