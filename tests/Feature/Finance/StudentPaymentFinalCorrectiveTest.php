<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Finance\CashSessionService;
use Illuminate\Support\Str;

/**
 * Student Payment Final Corrective — Cash Account Manual Selection +
 * Registration Receipt Warning.
 *
 * Scope: FinanceOperationsController::createPayment()/storePayment() (the
 * dashboard.invoices.payments.create/store route pair) only. No other
 * cash-accepting entry point (charge & collect, Quick Registration) is
 * touched — they keep auto-routing cash to the canonical operating account
 * exactly as before (see CanonicalPaymentAccountMappingTest, unchanged for
 * bank/instapay/card/owner). No PaymentAllocation/InvoicePaymentService
 * allocation-algorithm/refund/CashTransaction/installment/ServiceCoverage
 * semantic change — only which cash_account_id reaches the already-
 * canonical InvoicePaymentService::record() call, and which persisted
 * PaymentAllocation rows the receipt reads before showing the
 * non-refundable warning.
 */
class StudentPaymentFinalCorrectiveTest extends MassBillingTestCase
{
    private function registrationFee(string $amount = '500.00'): Fee
    {
        // is_non_refundable must be set explicitly — Fee defaults it to
        // false (see app/Models/Fee.php) regardless of category.
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true, 'is_non_refundable' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    // Stands in for the business example's "Transport" as a second,
    // non-registration, non-refundable-unrelated service — Transport/Food
    // themselves carry unrelated calendar/duration-selection validation
    // (see StudentPaymentAllocationUxTest's own docblock for why they're
    // avoided in these fixtures); the warning logic is driven entirely by
    // InvoiceItem::is_non_refundable, never by fee category name, so this
    // substitution changes nothing about what's being proven.
    private function booksFee(string $amount = '250.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Книги', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    private function drawer(string $name = 'Касса №2', bool $active = true): CashAccount
    {
        return CashAccount::create(['name' => $name, 'type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => $active]);
    }

    private function operatingAccountWithOpenSession(): CashAccount
    {
        $account = CashAccount::operating();
        if (! app(CashSessionService::class)->activeFor($account)) {
            app(CashSessionService::class)->open($account, $this->accountant);
        }

        return $account;
    }

    /** @return array{0: Invoice, 1: \App\Models\InvoiceItem} single-item Tuition invoice. */
    private function issueTuitionOnlyInvoice(string $suffix): array
    {
        $student = $this->enrolledStudent(suffix: $suffix);
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$this->tuition->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::where('student_id', $student->id)->sole();

        return [$invoice, $invoice->items->sole()];
    }

    /** @return array{0: Invoice, 1: \App\Models\InvoiceItem, 2: \App\Models\InvoiceItem, 3: \App\Models\InvoiceItem} Tuition+Registration+Books. */
    private function issueMixedInvoiceWithRegistration(string $suffix): array
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

    // ------------------------------------------------------------------
    // CASH ACCOUNT MANUAL SELECTION
    // ------------------------------------------------------------------

    // 1 & 2. Cash Account select is enabled/populated for cash payments.
    public function test_payment_form_lists_eligible_cash_drawer_accounts_for_manual_selection(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashFormList');
        $drawer = $this->drawer('Вторая касса');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $response->assertSee('data-cash-drawer="1"', false);
        $response->assertSee($drawer->name);
        $response->assertSee(CashAccount::operating()->name);
    }

    // 3 & 4. cash_account_id is required for Cash; missing account is rejected server-side.
    public function test_cash_payment_without_cash_account_id_is_rejected(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashMissingAccount');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('cash_account_id');
        $this->assertSame(0, InvoicePayment::count());
    }

    // 5. Invalid/ineligible/inactive account is rejected — inactive.
    public function test_cash_payment_with_an_inactive_account_is_rejected(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashInactiveAccount');
        $inactive = $this->drawer('Закрытая касса', active: false);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $inactive->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('cash_account_id');
        $this->assertSame(0, InvoicePayment::count());
    }

    // 5. Invalid/ineligible/inactive account is rejected — wrong type (bank, not a drawer).
    public function test_cash_payment_with_a_non_cash_type_account_is_rejected(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashWrongType');
        $bankAccount = CashAccount::bank();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $bankAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('cash_account_id');
        $this->assertSame(0, InvoicePayment::count());
    }

    // 5. Invalid/ineligible/inactive account is rejected — nonexistent id.
    public function test_cash_payment_with_a_nonexistent_account_is_rejected(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashNonexistent');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => 999999,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('cash_account_id');
        $this->assertSame(0, InvoicePayment::count());
    }

    // 6, 7, 8. Selected account is preserved; CashTransaction uses exactly
    // that account; no silent substitution/default to the canonical account.
    public function test_selected_cash_account_is_preserved_end_to_end_with_no_silent_substitution(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CashPreserved');
        $selected = $this->drawer('Касса приёмной');
        app(CashSessionService::class)->open($selected, $this->accountant);
        $canonicalOperating = CashAccount::operating();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $selected->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();
        $this->assertSame($selected->id, $payment->cash_account_id);
        $this->assertNotSame($canonicalOperating->id, $payment->cash_account_id);
        $this->assertSame($selected->id, CashTransaction::sole()->cash_account_id);
        $this->assertSame('1200.00', $selected->fresh()->balance);
        $this->assertSame('0.00', $canonicalOperating->fresh()->balance);
    }

    // 9. Existing non-cash behavior remains unchanged — bank still ignores
    // a submitted id and routes to the canonical bank account.
    public function test_bank_payment_still_ignores_submitted_account_and_uses_canonical_bank_account(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('BankUnchanged');
        $decoy = $this->drawer('Не банк');
        $bankAccount = CashAccount::bank();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'bank',
            'cash_account_id' => $decoy->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($bankAccount->id, InvoicePayment::sole()->cash_account_id);
    }

    // 9. Existing non-cash behavior remains unchanged — card still requires
    // (and honors) an explicit manual selection, exactly as before.
    public function test_card_payment_manual_selection_remains_unchanged(): void
    {
        [$invoice] = $this->issueTuitionOnlyInvoice('CardUnchanged');
        $bankAccount = CashAccount::bank();

        $missingAccount = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'card',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $missingAccount->assertSessionHasErrors('cash_account_id');

        $withAccount = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'card',
            'cash_account_id' => $bankAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $withAccount->assertSessionHasNoErrors();
        $this->assertSame($bankAccount->id, InvoicePayment::sole()->cash_account_id);
    }

    // ------------------------------------------------------------------
    // REGISTRATION NON-REFUNDABLE RECEIPT WARNING
    // ------------------------------------------------------------------

    // 10. Tuition-only current payment -> warning absent.
    public function test_tuition_only_payment_shows_no_registration_warning(): void
    {
        [$invoice, $tuitionItem] = $this->issueTuitionOnlyInvoice('WarnTuitionOnly');
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }

    // 11 & 15. Tuition + another (non-registration) service, on an invoice
    // that ALSO contains a Registration item -> warning absent. Proves the
    // invoice merely containing a Registration item is not sufficient.
    public function test_tuition_and_books_payment_on_a_registration_bearing_invoice_shows_no_warning(): void
    {
        [$invoice, $tuitionItem, $registrationItem, $booksItem] = $this->issueMixedInvoiceWithRegistration('WarnTuitionBooks');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1450.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00', $booksItem->id => '250.00'],
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }

    // 12. Registration-only current payment -> warning present.
    public function test_registration_only_payment_shows_the_warning(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoiceWithRegistration('WarnRegOnly');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$registrationItem->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertSee('Регистрационный взнос возврату не подлежит.');
    }

    // 13. Registration + Tuition current payment -> warning present.
    public function test_registration_and_tuition_payment_shows_the_warning(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoiceWithRegistration('WarnRegTuition');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1700.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00', $registrationItem->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $payment = InvoicePayment::sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertSee('Регистрационный взнос возврату не подлежит.');
    }

    // 14. A previous Registration payment does not cause the warning on a
    // later, non-registration payment against the same invoice.
    public function test_earlier_registration_payment_does_not_warn_on_a_later_non_registration_payment(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoiceWithRegistration('WarnEarlierReg');

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$registrationItem->id => '500.00'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$tuitionItem->id => '1200.00'],
        ])->assertSessionHasNoErrors();

        $laterPayment = InvoicePayment::where('amount', '1200.00')->sole();
        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $laterPayment));

        $response->assertOk();
        $response->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }

    // 16. Legacy payment with no allocations does not fabricate the warning,
    // even though the invoice itself contains a Registration item.
    public function test_legacy_unallocated_payment_never_fabricates_the_warning(): void
    {
        [$invoice] = $this->issueMixedInvoiceWithRegistration('WarnLegacy');
        $account = CashAccount::operating();
        $payment = $this->insertLegacyUnallocatedPayment($invoice, $account, '400.00');
        $this->assertSame(0, $payment->allocations()->count());

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));

        $response->assertOk();
        $response->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }

    // 17. HTML and PDF receipt behave consistently — both present when
    // allocated, both absent when not.
    public function test_html_and_pdf_receipt_agree_on_the_warning(): void
    {
        [$invoice, $tuitionItem, $registrationItem] = $this->issueMixedInvoiceWithRegistration('WarnHtmlPdf');
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$registrationItem->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $registrationPayment = InvoicePayment::sole();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.payments.receipt', $registrationPayment))
            ->assertOk()->assertSee('Регистрационный взнос возврату не подлежит.');
        $this->actingAs($this->accountant)
            ->get(route('dashboard.payments.receipt.pdf', $registrationPayment))
            ->assertOk();

        [$invoice2, $tuitionItem2] = $this->issueTuitionOnlyInvoice('WarnHtmlPdfNo');
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice2), [
            'amount' => '1200.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->operatingAccountWithOpenSession()->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $tuitionPayment = InvoicePayment::where('invoice_id', $invoice2->id)->sole();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.payments.receipt', $tuitionPayment))
            ->assertOk()->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }
}
