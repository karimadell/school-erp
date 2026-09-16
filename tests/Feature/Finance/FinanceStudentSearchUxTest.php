<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\InvoicePayment;

/**
 * Search/UX corrective — /dashboard/finance/income/students becomes a
 * clean, student-first Finance workspace: no global KPI cards, no
 * general-registry navigation, no inline invoice/item detail, and exactly
 * three reusable, already-canonical actions per student (Добавить услугу,
 * Финансовый счёт, Принять оплату). No new route, no accounting-domain
 * change — every assertion here is about what renders/links where and
 * that nothing about the underlying financial data changes by viewing it.
 */
class FinanceStudentSearchUxTest extends FinanceOperationsTestCase
{
    public function test_global_kpi_cards_are_removed(): void
    {
        $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertDontSee('Платежи сегодня');
    }

    public function test_search_finds_the_student_and_shows_class_and_year(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students', ['q' => 'Иванов']));

        $response->assertOk();
        $response->assertSee($this->student->full_name);
        $response->assertSee($this->student->currentEnrollment->schoolClass->name);
        $response->assertSee($this->year->name);
    }

    public function test_overdue_badge_shows_only_when_amount_is_positive(): void
    {
        $paidInvoice = $this->invoice('100.00', '2020-01-01');
        $paidInvoice->update(['status' => Invoice::STATUS_PAID, 'paid_amount' => '100.00', 'remaining_amount' => '0.00']);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertDontSee('Просрочено:');

        $this->invoice('500.00', '2020-01-01'); // overdue: due date in the past, unpaid

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));
        $response->assertOk();
        $response->assertSee('Просрочено:');
    }

    public function test_add_service_action_uses_the_canonical_picker_route(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee(__('finance_uat.add_service'));
        $response->assertSee(route('dashboard.students.add-service', $this->student), false);
    }

    public function test_student_account_action_uses_the_canonical_finance_route(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee(__('finance_uat.student_account'));
        $response->assertSee(route('dashboard.students.finance', $this->student), false);
    }

    public function test_accept_payment_links_directly_when_exactly_one_payable_invoice(): void
    {
        $invoice = $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee('Принять оплату');
        $response->assertSee(route('dashboard.invoices.payments.create', $invoice), false);
    }

    public function test_accept_payment_action_is_absent_when_nothing_is_payable(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertDontSee('Принять оплату');
    }

    public function test_open_profile_and_legacy_issue_invoice_actions_are_absent(): void
    {
        $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertDontSee('Открыть профиль');
        // route('dashboard.students.show', ...) is a substring of the
        // (still-present) "Финансовый счёт" URL, so check the exact href
        // attribute value instead of a raw substring.
        $response->assertDontSee('href="'.route('dashboard.students.show', $this->student).'"', false);
        $response->assertDontSee(route('dashboard.students.invoices.create', $this->student), false);
    }

    public function test_accountant_does_not_gain_student_or_enrollment_permissions(): void
    {
        $this->assertTrue($this->accountant->cannot('manage students'));
        $this->assertTrue($this->accountant->cannot('create enrollments'));
        $this->assertTrue($this->accountant->cannot('update enrollments'));
    }

    public function test_view_only_role_sees_no_write_actions(): void
    {
        $this->invoice('1200.00');
        $viewer = $this->user('reception');
        $viewer->givePermissionTo('view invoices');

        $response = $this->actingAs($viewer)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee($this->student->full_name);
        $response->assertSee(__('finance_uat.student_account'));
        $response->assertDontSee(__('finance_uat.add_service'));
        $response->assertDontSee('Принять оплату');
    }

    public function test_viewing_the_search_page_mutates_nothing(): void
    {
        $invoice = $this->invoice('1200.00');
        $invoiceCountBefore = Invoice::count();
        $paymentCountBefore = InvoicePayment::count();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['q' => 'Иванов', 'overdue' => 1]))
            ->assertOk();

        $this->assertSame($invoiceCountBefore, Invoice::count());
        $this->assertSame($paymentCountBefore, InvoicePayment::count());
        $this->assertSame('1200.00', $invoice->fresh()->total_amount);
    }
}
