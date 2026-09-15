<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\ServiceCoverage;
use Illuminate\Support\Str;

/**
 * Finance Workspace corrective PR #3 — unifies the two competing new-charge
 * actions on the Student Financial Account page ("Выставить счёт" / classic
 * Invoice, and "Начислить и принять оплату" / Charge & Collect) behind one
 * "Добавить услугу" picker (dashboard.finance.add-service).
 *
 * The picker is a pure router: it mutates nothing, and every tile lands on
 * an existing, completely unchanged issuance flow — Food into
 * ChargeAndCollectService (the only engine that supports it), every other
 * category into InvoiceIssuanceService via the modern Classic Invoice
 * controller (the only engine that still supports payment plans, discounts
 * and multi-service invoicing — see FinanceOperationsController::
 * addServiceSelect()'s own docblock for why ChargeAndCollectService's
 * non-Food path cannot safely absorb those categories).
 *
 * Food-specific business rules (duration modes, overlap protection,
 * idempotency, AcademicCalendar-aware pricing) and Classic Invoice's own
 * idempotency/payment-plan/registration-guard behaviour are NOT
 * re-verified here — they belong to, and are already exhaustively covered
 * by, ChargeAndCollectFoodTest / ClassicInvoiceIdempotencyTest /
 * StudentInvoicePaymentPlanScopingTest / InvoiceIssuanceParityTest, none of
 * which this PR touches. This file proves only the new entry point itself:
 * visibility, non-mutation on navigation, correct routing per category, and
 * permission parity with what each destination already requires.
 */
class StudentAddServiceEntryPointTest extends FinanceOperationsTestCase
{
    // ----- 1/2. One unified action, no more competing buttons --------------

    public function test_student_page_shows_only_the_unified_add_service_action(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Добавить услугу');
        $response->assertSee(route('dashboard.students.add-service', $this->student), false);

        $response->assertDontSee('Выставить счёт');
        $response->assertDontSee('Начислить и принять оплату');
        $response->assertDontSee(route('dashboard.students.invoices.create', $this->student), false);
        $response->assertDontSee(route('dashboard.students.charge.create', $this->student), false);
    }

    // ----- 3. Opening the picker mutates nothing ----------------------------

    public function test_opening_the_add_service_picker_creates_no_finance_mutation(): void
    {
        $this->assertSame(0, Invoice::count());

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.add-service', $this->student))
            ->assertOk();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, ServiceCoverage::count());
    }

    // ----- 4/8. Correct routing per business service ------------------------

    public function test_food_tile_routes_into_the_existing_charge_and_collect_screen(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        $response->assertSee('Питание');
        $response->assertSee(route('dashboard.students.charge.create', $this->student), false);
    }

    public function test_non_food_tiles_route_into_the_existing_classic_invoice_screen(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        foreach (['Обучение', 'Трансфер', 'Школьная форма', 'Дополнительные занятия', 'Мероприятия и поездки', 'Прочие услуги'] as $label) {
            $response->assertSee($label);
        }
        // Every non-Food tile shares the exact same destination — Classic
        // Invoice already lets the accountant pick one or several services
        // together in a single invoice; the tiles only help the accountant
        // recognize the business concept by name.
        $response->assertSee(route('dashboard.students.invoices.create', $this->student), false);
    }

    // ----- 9/10/11. Non-Food path: reused engine, partial payment semantics -

    public function test_selecting_tuition_reaches_and_completes_the_unchanged_classic_invoice_flow(): void
    {
        $key = (string) Str::uuid();

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$this->fee->id], 'payment_type' => 'one_time',
            'idempotency_key' => $key,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Invoice::count());

        // Replaying the same key must still return the original invoice,
        // not a duplicate — PR #54's idempotency fix, unaffected by the new
        // entry point sitting in front of this unchanged controller.
        $this->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$this->fee->id], 'payment_type' => 'one_time',
            'idempotency_key' => $key,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    public function test_a_partial_payment_on_a_new_food_service_leaves_correct_debt_and_never_touches_an_old_invoice(): void
    {
        // "Old debt" — an unrelated, already-existing invoice for this
        // student, created before the new service is ever added.
        $oldInvoice = $this->invoice('1200.00');
        $this->assertSame('0.00', $oldInvoice->paid_amount);
        $this->assertSame('1200.00', $oldInvoice->remaining_amount);

        $calendar = AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $mealPlan = MealPlan::create(['name_ru' => 'Полный рацион', 'meal_type' => 'both', 'period' => 'daily', 'price' => '100.00', 'is_active' => true]);
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id,
            'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        // Reaches this exact screen from the "Питание" tile on the picker.
        $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $food->id,
            'meal_plan_id' => $mealPlan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-09-01',
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '40.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $newInvoice = Invoice::where('id', '!=', $oldInvoice->id)->sole();

        $this->assertSame('100.00', $newInvoice->total_amount);
        $this->assertSame('40.00', $newInvoice->paid_amount);
        $this->assertSame('60.00', $newInvoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $newInvoice->status);

        // The old invoice is completely untouched.
        $oldInvoice->refresh();
        $this->assertSame('1200.00', $oldInvoice->total_amount);
        $this->assertSame('0.00', $oldInvoice->paid_amount);
        $this->assertSame('1200.00', $oldInvoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $oldInvoice->status);
    }

    // ----- 12/13. Permission parity — no dead/403 unified action -----------

    public function test_role_without_manage_invoices_does_not_see_the_add_service_action(): void
    {
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));
        $response->assertOk();
        $response->assertDontSee('Добавить услугу');
        $response->assertDontSee(route('dashboard.students.add-service', $this->student), false);

        $this->get(route('dashboard.students.add-service', $this->student))->assertForbidden();
    }

    public function test_manage_invoices_roles_see_and_can_use_the_add_service_action(): void
    {
        foreach (['admin', 'principal', 'school-admin', 'cashier'] as $role) {
            $user = $this->user($role);

            $response = $this->actingAs($user)->get(route('dashboard.students.finance', $this->student));
            $response->assertOk();
            $response->assertSee('Добавить услугу');

            $this->get(route('dashboard.students.add-service', $this->student))->assertOk();
        }
    }

    public function test_teacher_never_reaches_the_add_service_action(): void
    {
        $teacher = $this->user('teacher');

        $this->actingAs($teacher)
            ->get(route('dashboard.students.add-service', $this->student))
            ->assertStatus(302);
    }

    // ----- 14. PR #55's Enrollment action is unaffected ---------------------

    public function test_pr55_enrollment_action_visibility_is_unchanged_by_this_page(): void
    {
        $reception = $this->user('reception');
        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));
        $response->assertOk();
        $response->assertSee('Зачислить на новый учебный год');
        $response->assertSee(route('dashboard.enrollments.create', $this->student), false);

        $accountantResponse = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));
        $accountantResponse->assertOk();
        $accountantResponse->assertDontSee('Зачислить на новый учебный год');
    }
}
