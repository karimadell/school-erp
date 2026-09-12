<?php

namespace Tests\Feature\Finance;

/**
 * Phase B (post-save Print/PDF/profile/next-payment actions) and Phase D
 * (an "existing student" entry point on the Quick Registration screen that
 * hands off into the already-built, already-tested finance workspace
 * instead of duplicating it).
 */
class QuickRegistrationExistingStudentAndPostSaveUxTest extends FinanceOperationsTestCase
{
    // Finance landing page corrective: the existing-student search now
    // submits into income.students (student billing lives under Приход,
    // not on the simplified Финансы landing page) — same destination
    // behavior, just relocated.
    public function test_quick_registration_screen_offers_an_existing_student_search_into_the_finance_workspace(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee('Существующий ученик')
            ->assertSee(route('dashboard.finance.income.students', absolute: false), false);
    }

    public function test_payment_receipt_exposes_view_profile_and_next_payment_links(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->accountant)
            ->post(route('dashboard.invoices.payments.store', $invoice), [
                'amount' => '500.00',
                'payment_method' => 'cash',
                // Student Payment Final Corrective — cash_account_id is now
                // required for a cash payment (the operator must name the
                // drawer that received the money); $this->cash is the
                // FinanceOperationsTestCase fixture's already-open-session
                // operating drawer.
                'cash_account_id' => $this->cash->id,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ])->assertSessionHasNoErrors();

        $payment = $invoice->payments()->sole();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.payments.receipt', $payment))
            ->assertOk()
            ->assertSee(route('dashboard.invoices.show', $invoice), false)
            ->assertSee(route('dashboard.students.show', $this->student), false)
            ->assertSee(route('dashboard.invoices.payments.create', $invoice), false);
    }

    public function test_invoice_show_exposes_a_student_profile_link(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.show', $invoice))
            ->assertOk()
            ->assertSee(route('dashboard.students.show', $this->student), false);
    }
}
