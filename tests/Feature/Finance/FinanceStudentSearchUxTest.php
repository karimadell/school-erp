<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;

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

    /**
     * Unified Cashier Workspace (PR C1) corrective — "Принять оплату" now
     * opens the Unified Collection workspace (which itself lists every
     * outstanding obligation, however many invoices they span) instead of
     * the old conditional single-invoice/finance-page fallback. Intentional
     * behavior change, approved as part of PR C1's own scope.
     */
    public function test_accept_payment_links_to_the_unified_collection_workspace(): void
    {
        $invoice = $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee('Принять оплату');
        $response->assertSee(route('dashboard.students.unified-collection.create', $this->student), false);
    }

    /**
     * Unified Cashier Workspace (PR C1) corrective — "Принять оплату" is
     * now shown for any manage-invoices actor regardless of whether an
     * existing payable invoice currently exists: the workspace is also
     * how a new service gets charged+collected, not only how existing
     * debt gets paid.
     */
    public function test_accept_payment_action_is_present_even_when_nothing_is_currently_payable(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee('Принять оплату');
        $response->assertSee(route('dashboard.students.unified-collection.create', $this->student), false);
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

    /*
     * Finance income workflow UX corrective — ?context=payment|service is
     * a purely presentational discriminator on this SAME route/controller/
     * query (see FinanceOperationsController::students()). It changes only
     * which of the two already-canonical row actions (Принять оплату →
     * Unified Collection, Добавить услугу → the Add Service picker) is
     * styled as primary vs secondary, plus the page title/hint. No new
     * route, no accounting-domain change, no permission change.
     */

    public function test_payment_context_shows_the_payment_specific_title_and_hint(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'payment']));

        $response->assertOk();
        $response->assertSee(__('finance_workspace.income_students_title_payment'));
        $response->assertSee(__('finance_workspace.income_students_hint_payment'));
        $response->assertDontSee(__('finance_workspace.income_students_title_service'));
    }

    public function test_service_context_shows_the_service_specific_title_and_hint(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'service']));

        $response->assertOk();
        $response->assertSee(__('finance_workspace.income_students_title_service'));
        $response->assertSee(__('finance_workspace.income_students_hint_service'));
        $response->assertDontSee(__('finance_workspace.income_students_title_payment'));
    }

    public function test_payment_context_renders_accept_payment_as_the_primary_row_action(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'payment']));

        $response->assertOk();
        // Принять оплату: solid/primary styling.
        $response->assertSee('class="btn btn-sm btn-success" href="'.route('dashboard.students.unified-collection.create', $this->student).'"', false);
        // Добавить услугу: outline/secondary styling, still present and
        // still the exact same canonical route — never removed, never a
        // different destination.
        $response->assertSee('class="btn btn-sm btn-outline-primary" href="'.route('dashboard.students.add-service', $this->student).'"', false);
    }

    public function test_service_context_renders_add_service_as_the_primary_row_action(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'service']));

        $response->assertOk();
        // Добавить услугу: solid/primary styling.
        $response->assertSee('class="btn btn-sm btn-primary" href="'.route('dashboard.students.add-service', $this->student).'"', false);
        // Принять оплату: outline/secondary styling, still present and
        // still the exact same canonical route.
        $response->assertSee('class="btn btn-sm btn-outline-success" href="'.route('dashboard.students.unified-collection.create', $this->student).'"', false);
    }

    public function test_no_context_preserves_todays_neutral_row_action_styling(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));

        $response->assertOk();
        $response->assertSee('class="btn btn-sm btn-primary" href="'.route('dashboard.students.add-service', $this->student).'"', false);
        $response->assertSee('class="btn btn-sm btn-success" href="'.route('dashboard.students.unified-collection.create', $this->student).'"', false);
        $response->assertSee(__('finance_workspace.income_students_title'));
    }

    public function test_invalid_arbitrary_context_safely_normalizes_to_neutral(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'delete-everything']));

        $response->assertOk();
        $response->assertSee(__('finance_workspace.income_students_hint'));
        // The payment/service hints are NOT substrings of the neutral
        // title/hint (unlike the titles, which share a common prefix), so
        // this is the reliable signal that the context was ignored/
        // normalized, not merely a title coincidence.
        $response->assertDontSee(__('finance_workspace.income_students_hint_payment'));
        $response->assertDontSee(__('finance_workspace.income_students_hint_service'));
        // The exact same today's-neutral styling, not a crash or a
        // half-applied context.
        $response->assertSee('class="btn btn-sm btn-primary" href="'.route('dashboard.students.add-service', $this->student).'"', false);
        $response->assertSee('class="btn btn-sm btn-success" href="'.route('dashboard.students.unified-collection.create', $this->student).'"', false);
    }

    public function test_stolovaya_entry_point_keeps_neutral_context_behavior(): void
    {
        // PR 3: the accountant may use both Stolovaya sides, so
        // IncomeEntryController::stolovaya() shows the chooser; its Student
        // option links here with no "context" at all — must keep exactly
        // today's neutral rendering, never payment/service framing.
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.stolovaya'));
        $response->assertOk();
        $response->assertSee('href="'.route('dashboard.finance.income.students').'"', false);

        $followed = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students'));
        $followed->assertOk();
        $followed->assertSee(__('finance_workspace.income_students_title'));
    }

    public function test_context_survives_a_search_round_trip_via_a_hidden_field(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'payment', 'q' => 'Иванов']));

        $response->assertOk();
        $response->assertSee('<input type="hidden" name="context" value="payment">', false);
    }

    public function test_context_survives_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Student::create([
                'last_name_ru' => 'Тест'.$i,
                'first_name_ru' => 'Ученик',
                'phone' => '+201000000'.sprintf('%03d', $i),
                'class_id' => $this->student->class_id,
                'status' => 'registration_completed',
            ]);
        }

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'payment']));

        $response->assertOk();
        preg_match_all('/href="([^"]*page=2[^"]*)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1], 'Expected a page-2 pagination link to exist (26 students, 25 per page).');
        $this->assertStringContainsString('context=payment', urldecode($matches[1][0]));
    }

    public function test_row_action_route_targets_remain_canonical_in_every_context(): void
    {
        foreach ([null, 'payment', 'service'] as $context) {
            $params = $context ? ['context' => $context] : [];
            $response = $this->actingAs($this->accountant)
                ->get(route('dashboard.finance.income.students', $params));

            $response->assertOk();
            $response->assertSee(route('dashboard.students.unified-collection.create', $this->student), false);
            $response->assertSee(route('dashboard.students.add-service', $this->student), false);
            $response->assertSee(route('dashboard.students.stolovaya.create', $this->student), false);
            $response->assertSee(route('dashboard.students.finance', $this->student), false);
        }
    }
}
