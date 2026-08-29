<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Invoice;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\StudentFinanceSummaryService;

/**
 * Phase 1 — Quick Registration document semantics regression coverage.
 *
 * Quick Registration keeps creating a full internal obligation (Invoice +
 * InvoiceItem + InvoiceInstallment) even when paid_now = 0 — Student
 * Finance's only data source is Invoice, so removing it would silently
 * erase the debt. What changes is presentation only: a zero-payment
 * Quick Registration invoice is excluded from the default "Счета" list
 * (origin=quick_registration AND paid_amount=0), while every other
 * unpaid invoice — Classic Invoice, Mass Billing, Charge & Collect, or
 * any invoice with no recorded origin — keeps appearing exactly as
 * before. The row itself is never actually hidden: it's reachable by
 * direct route, an explicit reveal toggle, and always counted by
 * Student Finance regardless of this list filter.
 */
class QuickRegistrationDocumentSemanticsTest extends QuickRegistrationUxTestCase
{
    private function unpaidClassicInvoice(): Invoice
    {
        $structure = $this->structure();
        [$year] = $structure;
        $student = \App\Models\Student::create([
            'last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'phone' => '+20 100 111 2233',
        ]);
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '500.00', 'total_amount' => '500.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();

        return $invoice;
    }

    public function test_quick_registration_invoice_gets_origin_quick_registration(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(Invoice::ORIGIN_QUICK_REGISTRATION, Invoice::sole()->origin);
    }

    public function test_invoice_issuance_without_origin_still_stores_null(): void
    {
        $invoice = $this->unpaidClassicInvoice();

        $this->assertNull($invoice->fresh()->origin);
    }

    public function test_default_invoice_list_excludes_zero_payment_quick_registration_obligation(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]))->assertSessionHasNoErrors();
        $invoice = Invoice::sole();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.index'))
            ->assertOk()
            ->assertDontSee($invoice->invoice_number);

        // Never actually hidden: direct route and Student Finance both
        // still see it in full.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number);
    }

    public function test_reveal_toggle_makes_the_hidden_obligation_visible(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]))->assertSessionHasNoErrors();
        $invoice = Invoice::sole();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.index', ['show_unpaid_quick_registration' => 1]))
            ->assertOk()
            ->assertSee($invoice->invoice_number);
    }

    public function test_zero_payment_invoice_with_null_origin_still_appears_in_default_list(): void
    {
        $invoice = $this->unpaidClassicInvoice();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number);
    }

    public function test_student_finance_still_counts_the_hidden_quick_registration_obligation(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]))->assertSessionHasNoErrors();

        $student = \App\Models\Student::sole()->fresh();
        $summary = app(StudentFinanceSummaryService::class)->summarize($student);

        $this->assertSame('1000.00', $summary['gross_invoiced']);
        $this->assertSame('1000.00', $summary['gross_remaining']);
        $this->assertSame('1000.00', $summary['net_outstanding']);
    }
}
