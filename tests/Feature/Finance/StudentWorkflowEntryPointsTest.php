<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;

/**
 * Finance Workspace corrective PR #2 — navigation/entry-point discoverability
 * only. Both workflows reuse existing, unchanged engines:
 *
 * - "Новый ученик" is a new sidebar link straight into the existing Quick
 *   Registration create route, gated on the exact permission
 *   (QuickStudentRegistrationController::__construct()) that route already
 *   requires.
 * - "Зачислить на новый учебный год" is a new button on the Student
 *   Financial Account page straight into the existing
 *   EnrollmentController::create()/store() flow, gated on the exact
 *   'create enrollments' permission that controller already requires (never
 *   'update enrollments', which only guards edit()/update() on an EXISTING
 *   enrollment record — not relevant to creating a new one).
 *
 * No permission grants changed anywhere in this pass — every assertion here
 * follows directly from RolesAndPermissionsSeeder's existing grants.
 */
class StudentWorkflowEntryPointsTest extends FinanceOperationsTestCase
{
    public function test_manage_invoices_role_sees_and_can_use_the_new_student_entry_point(): void
    {
        // $this->accountant has 'manage invoices' (RolesAndPermissionsSeeder).
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Новый ученик');
        $response->assertSee(route('dashboard.quick-registration.create'), false);

        $this->get(route('dashboard.quick-registration.create'))->assertOk();
    }

    public function test_role_without_manage_invoices_does_not_receive_a_dead_new_student_link(): void
    {
        // Reception has 'view invoices' (reaches the dashboard shell/Финансы
        // pages) but not 'manage invoices' (which QuickStudentRegistrationController
        // requires for every action, including create()).
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));
        $response->assertOk();
        $response->assertDontSee('Новый ученик');
        $response->assertDontSee(route('dashboard.quick-registration.create'), false);

        // Confirms the link would have been a dead end had it been shown —
        // proves the gate matches the real backend requirement, not just UI.
        $this->get(route('dashboard.quick-registration.create'))->assertForbidden();
    }

    public function test_income_entry_quick_registration_card_is_hidden_from_a_role_without_manage_invoices(): void
    {
        // Pre-existing bug fixed alongside the new entry point: this card
        // (dashboard.finance.income.index) previously had no permission gate
        // at all, even though it links to the same 'manage invoices'-only
        // route.
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.finance.income.index'));
        $response->assertOk();
        $response->assertDontSee(route('dashboard.quick-registration.create'), false);
    }

    public function test_income_entry_quick_registration_card_remains_visible_to_manage_invoices_role(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.index'));

        $response->assertOk();
        $response->assertSee(route('dashboard.quick-registration.create'), false);
    }

    public function test_role_with_create_enrollments_sees_the_new_year_enrollment_action_for_an_existing_student(): void
    {
        // Reception has 'create enrollments'.
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Зачислить на новый учебный год');
        $response->assertSee(route('dashboard.enrollments.create', $this->student), false);
    }

    public function test_accountant_does_not_see_the_new_year_enrollment_action(): void
    {
        // The explicit product decision this PR must respect: accountant
        // keeps only 'view enrollments', never 'create'/'update enrollments'.
        $this->assertFalse($this->accountant->can('create enrollments'));

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertDontSee('Зачислить на новый учебный год');
        $response->assertDontSee(route('dashboard.enrollments.create', $this->student), false);

        // The action stays inert even by direct URL — confirms this is a
        // pure visibility fix, not a permission change.
        $this->get(route('dashboard.enrollments.create', $this->student))->assertForbidden();
    }

    public function test_cashier_does_not_gain_enrollment_capability(): void
    {
        $cashier = $this->user('cashier');
        $this->assertFalse($cashier->can('create enrollments'));

        $response = $this->actingAs($cashier)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertDontSee('Зачислить на новый учебный год');
        $this->get(route('dashboard.enrollments.create', $this->student))->assertForbidden();
    }

    public function test_teacher_remains_excluded_from_finance_and_enrollment_entry_points(): void
    {
        $teacher = $this->user('teacher');

        // EnsureAdministrativePortalAccess redirects any non-administrative
        // role away before the dashboard shell (and therefore the sidebar
        // and the Student Financial Account page) ever renders.
        $this->actingAs($teacher)->get(route('dashboard.students.finance', $this->student))->assertStatus(302);
        $this->actingAs($teacher)->get(route('dashboard.quick-registration.create'))->assertStatus(302);
        $this->actingAs($teacher)->get(route('dashboard.enrollments.create', $this->student))->assertStatus(302);
    }

    public function test_new_year_enrollment_action_opens_the_existing_enrollment_form_for_the_existing_student(): void
    {
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.enrollments.create', $this->student));

        $response->assertOk();
        $response->assertViewIs('dashboard.enrollments.create');
        $response->assertViewHas('student', fn (Student $student) => $student->is($this->student));
    }

    public function test_entering_the_new_year_enrollment_workflow_creates_no_duplicate_student(): void
    {
        $reception = $this->user('reception');
        $before = Student::count();

        $this->actingAs($reception)->get(route('dashboard.enrollments.create', $this->student))->assertOk();

        $this->assertSame($before, Student::count());
    }

    public function test_entering_either_new_workflow_creates_no_finance_mutation(): void
    {
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();
        $this->actingAs($this->user('reception'))->get(route('dashboard.enrollments.create', $this->student))->assertOk();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_existing_finance_navigation_remains_functional(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        foreach ([
            'Финансы', 'Приход', 'Расход', 'Касса', 'Отчёты',
        ] as $expected) {
            $response->assertSee($expected);
        }

        $this->get(route('dashboard.finance.workspace'))->assertOk();
        $this->get(route('dashboard.finance.income.index'))->assertOk();
    }
}
