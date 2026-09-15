<?php

namespace Tests\Feature\Finance;

/**
 * Finance navigation corrective — PR #55's standalone "Новый ученик" sidebar
 * shortcut under ФИНАНСЫ is removed: having Quick Registration surfaced as
 * its own top-level Finance sidebar entry made it look like a separate
 * module, rather than the first-registration step of the Income (Приход)
 * workflow it actually is. Quick Registration's route, controller,
 * permissions, and its existing "Новый ученик / Регистрация" card inside
 * Приход (gated by the same PR #2 pass) are completely unchanged — only
 * this redundant shortcut link is gone.
 *
 * Every other merged Finance workflow (returning-student Enrollment
 * handoff, Добавить услугу, the active-year Enrollment guard, Food/non-Food
 * routing) is untouched by this pass; this file re-confirms each remains
 * intact after the navigation change, without re-deriving their own
 * business logic (already covered by ReturningStudentFinanceHandoffTest /
 * StudentAddServiceEntryPointTest / StudentWorkflowEntryPointsTest, none of
 * which this pass modifies beyond the sidebar-specific assertions removed
 * there).
 */
class FinanceNavigationNewStudentShortcutTest extends FinanceOperationsTestCase
{
    public function test_sidebar_no_longer_shows_a_standalone_new_student_shortcut(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertDontSee('Новый ученик');
    }

    public function test_sidebar_still_shows_the_five_finance_entries_per_existing_permissions(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        foreach (['Финансы', 'Приход', 'Расход', 'Касса', 'Отчёты'] as $expected) {
            $response->assertSee($expected);
        }
    }

    public function test_income_workflow_still_exposes_the_new_student_registration_card(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.index'));

        $response->assertOk();
        $response->assertSee(route('dashboard.quick-registration.create'), false);
    }

    public function test_quick_registration_route_remains_accessible_per_existing_permission(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $reception = $this->user('reception');
        $this->actingAs($reception)->get(route('dashboard.quick-registration.create'))->assertForbidden();
    }

    public function test_returning_student_enrollment_action_remains_available_per_create_enrollments_permission(): void
    {
        $reception = $this->user('reception');
        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Зачислить на новый учебный год');
        $response->assertSee(route('dashboard.enrollments.create', $this->student), false);
    }

    public function test_accountant_still_cannot_create_enrollment(): void
    {
        $this->assertFalse($this->accountant->can('create enrollments'));

        $this->actingAs($this->accountant)
            ->get(route('dashboard.enrollments.create', $this->student))
            ->assertForbidden();
    }

    public function test_add_service_remains_available_for_an_eligible_existing_student(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        $response->assertSee('Питание');
    }

    public function test_missing_active_year_enrollment_guard_remains_intact(): void
    {
        $this->enrollment->delete();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.add-service', $this->student))
            ->assertRedirect(route('dashboard.students.finance', $this->student));
    }

    public function test_food_routing_remains_unchanged(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        $response->assertSee(route('dashboard.students.charge.create', $this->student), false);
    }

    public function test_non_food_routing_remains_unchanged(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        $response->assertSee(route('dashboard.students.invoices.create', $this->student), false);
    }
}
