<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Services\Finance\StudentFinanceSummaryService;
use App\Support\AcademicYearLock;
use Illuminate\Support\Str;

/**
 * Finance Workspace corrective PR #5 — returning student / active-year
 * Finance handoff. The read-only audit found no data-safety gap: new
 * invoice issuance was already hard-pinned to the active academic year by
 * InvoiceIssuanceService::issue()'s own is_active + active-Enrollment
 * check, which every issuance path (Classic Invoice, Charge & Collect,
 * Quick Registration, Mass Billing) already funnels through. This PR adds
 * only the missing UX clarity on top of that existing guarantee:
 *
 * - a status banner on Финансы ученика when the Student has no Enrollment
 *   for the currently active AcademicYear (never merely "no Enrollment at
 *   all" — $student->currentEnrollment is already scoped to the active
 *   year by its own relation definition);
 * - the same guard enforced server-side in addServiceSelect(), not just
 *   hidden client-side;
 * - an explicit "this will post to the active year" note when Финансы
 *   ученика is showing a historical year but a new service can still be
 *   added.
 *
 * No selected/historical year is ever passed into issuance — every
 * assertion here that a new invoice landed on the active year is proven
 * through the SAME unmodified StudentInvoiceController/
 * FinanceOperationsController::chargeCreate() routes PR #56 already wired,
 * never a new parameter this PR introduces.
 */
class ReturningStudentFinanceHandoffTest extends FinanceOperationsTestCase
{
    private int $stageId;

    private int $gradeId;

    private int $classId;

    private ?int $modeId;

    /**
     * Removes the base fixture's active-year Enrollment and replaces it
     * with a historical-year-only one for the same Student — the exact
     * "only historical Enrollment(s), none for the active year" scenario
     * this PR's guard exists for. Returns the historical AcademicYear.
     */
    private function leaveOnlyHistoricalEnrollment(): AcademicYear
    {
        $this->stageId = $this->enrollment->stage_id;
        $this->gradeId = $this->enrollment->grade_id;
        $this->classId = $this->enrollment->class_id;
        $this->modeId = $this->enrollment->enrollment_mode_id;
        $this->enrollment->delete();

        $previousYear = AcademicYear::create([
            'name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false,
        ]);
        AcademicYearLock::withoutLock(fn () => Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $previousYear->id,
            'enrollment_mode_id' => $this->modeId, 'stage_id' => $this->stageId, 'grade_id' => $this->gradeId, 'class_id' => $this->classId,
            'academic_year' => $previousYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
            'status' => 'active', 'is_active' => true,
        ]));

        return $previousYear;
    }

    private function invoiceForYear(AcademicYear $year, string $total): Invoice
    {
        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $year->id, 'customer_name' => $this->student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => $total, 'total_amount' => $total, 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => $total, 'status' => Invoice::STATUS_UNPAID,
            'due_date' => $year->end_date, 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, $year->name);
        $invoice->save();
        InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение', 'unit_price' => $total, 'quantity' => 1, 'amount' => $total, 'paid_amount' => '0.00', 'remaining_amount' => $total]);

        return $invoice;
    }

    // ----- 1/2. Active-year status banner --------------------------------

    public function test_student_with_active_year_enrollment_shows_no_missing_enrollment_warning(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertDontSee('Требуется зачисление');
        $response->assertDontSee('Активный учебный год не настроен');
    }

    public function test_student_with_only_historical_enrollment_shows_missing_active_year_warning(): void
    {
        $this->leaveOnlyHistoricalEnrollment();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Требуется зачисление на '.$this->year->name.' учебный год.');
    }

    // ----- 3/4/5. Authorized Operations vs accountant vs cashier ----------

    public function test_authorized_create_enrollments_user_sees_the_enrollment_action_alongside_the_warning(): void
    {
        $this->leaveOnlyHistoricalEnrollment();
        $reception = $this->user('reception');

        $response = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Требуется зачисление на '.$this->year->name.' учебный год.');
        $response->assertSee(route('dashboard.enrollments.create', $this->student), false);
    }

    public function test_accountant_sees_status_but_no_enrollment_action(): void
    {
        $this->leaveOnlyHistoricalEnrollment();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertSee('Требуется зачисление на '.$this->year->name.' учебный год.');
        $response->assertSee('Обратитесь к сотруднику, ответственному за зачисление.');
        $response->assertDontSee(route('dashboard.enrollments.create', $this->student), false);
    }

    public function test_cashier_does_not_receive_a_dead_enrollment_action(): void
    {
        $this->leaveOnlyHistoricalEnrollment();
        $cashier = $this->user('cashier');

        $response = $this->actingAs($cashier)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk();
        $response->assertDontSee(route('dashboard.enrollments.create', $this->student), false);
    }

    public function test_accountant_still_cannot_create_enrollment_by_direct_url(): void
    {
        $this->leaveOnlyHistoricalEnrollment();
        $this->assertFalse($this->accountant->can('create enrollments'));

        $this->actingAs($this->accountant)
            ->get(route('dashboard.enrollments.create', $this->student))
            ->assertForbidden();
    }

    // ----- 7/8. Add Service direct-URL guard --------------------------------

    public function test_add_service_direct_url_is_safely_blocked_without_active_year_enrollment(): void
    {
        $this->leaveOnlyHistoricalEnrollment();

        $this->actingAs($this->accountant);
        $response = $this->get(route('dashboard.students.add-service', $this->student));

        $response->assertRedirect(route('dashboard.students.finance', $this->student));
        $response->assertSessionHas('error', 'Требуется зачисление на '.$this->year->name.' учебный год.');

        // Follow the redirect — the destination page shows the same status,
        // never a bare/dead picker.
        $this->followingRedirects()->get(route('dashboard.students.add-service', $this->student))
            ->assertSee('Требуется зачисление на '.$this->year->name.' учебный год.');
    }

    public function test_add_service_works_normally_when_active_year_enrollment_exists(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student));

        $response->assertOk();
        $response->assertSee('Питание');
        $response->assertSee('Добавление услуги на '.$this->year->name.' учебный год.');
    }

    public function test_no_active_academic_year_configured_shows_warning_and_blocks_add_service(): void
    {
        $this->year->update(['is_active' => false]);

        $page = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));
        $page->assertOk();
        $page->assertSee('Активный учебный год не настроен. Обратитесь к администратору.');

        $picker = $this->get(route('dashboard.students.add-service', $this->student));
        $picker->assertRedirect(route('dashboard.students.finance', $this->student));
        $picker->assertSessionHas('error', 'Активный учебный год не настроен. Обратитесь к администратору.');
    }

    // ----- 9/10. Historical year viewed, active Enrollment exists ----------

    public function test_viewing_historical_year_notes_the_new_service_targets_the_active_year(): void
    {
        $previousYear = $this->leaveOnlyHistoricalEnrollment();
        // Restore an active-year Enrollment too, so this test isolates the
        // "viewing a historical year while still eligible to add services"
        // case from the "missing enrollment" case above.
        AcademicYearLock::withoutLock(fn () => Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->modeId, 'stage_id' => $this->stageId, 'grade_id' => $this->gradeId, 'class_id' => $this->classId,
            'academic_year' => $this->year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]));

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$previousYear->id);

        $response->assertOk();
        $response->assertDontSee('Требуется зачисление');
        $response->assertSee('Новая услуга будет начислена на '.$this->year->name.' учебный год.');
    }

    public function test_historical_year_view_does_not_cause_issuance_into_the_historical_year(): void
    {
        $previousYear = $this->leaveOnlyHistoricalEnrollment();
        AcademicYearLock::withoutLock(fn () => Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->modeId, 'stage_id' => $this->stageId, 'grade_id' => $this->gradeId, 'class_id' => $this->classId,
            'academic_year' => $this->year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]));

        // Accountant is viewing the historical year on the student page...
        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$previousYear->id)
            ->assertOk();

        // ...but the picker and issuance routes never read that selection —
        // they always resolve the active year independently.
        $this->get(route('dashboard.students.add-service', $this->student))->assertOk();
        $this->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$this->fee->id], 'payment_type' => 'one_time',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame($this->year->id, $invoice->academic_year_id);
        $this->assertNotSame($previousYear->id, $invoice->academic_year_id);
    }

    // ----- 16. No mutation from viewing warnings/picker ---------------------

    public function test_viewing_the_warning_and_the_picker_causes_no_finance_mutation(): void
    {
        $this->leaveOnlyHistoricalEnrollment();
        $studentCount = Student::count();
        $invoiceCount = Invoice::count();

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))->assertOk();
        $this->get(route('dashboard.students.add-service', $this->student))->assertRedirect();

        $this->assertSame($studentCount, Student::count());
        $this->assertSame($invoiceCount, Invoice::count());
    }

    // ----- Critical end-to-end returning-student scenario -------------------

    public function test_end_to_end_returning_student_workflow_across_two_academic_years(): void
    {
        $previousYear = $this->leaveOnlyHistoricalEnrollment();
        $previousInvoice = $this->invoiceForYear($previousYear, '10000.00');
        $studentCountBefore = Student::count();

        // STEP 1 — authorized Operations user creates Enrollment B for the
        // SAME Student, active year, via the existing PR #55 route.
        $reception = $this->user('reception');
        $this->actingAs($reception)->post(route('dashboard.enrollments.store', $this->student), [
            'academic_year_id' => $this->year->id, 'enrollment_mode_id' => $this->modeId,
            'stage_id' => $this->stageId, 'grade_id' => $this->gradeId, 'class_id' => $this->classId,
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($studentCountBefore, Student::count(), 'no Student duplication');
        $newEnrollment = Enrollment::where('student_id', $this->student->id)->where('academic_year_id', $this->year->id)->sole();
        $this->assertSame($this->student->id, $newEnrollment->student_id);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'academic_year_id' => $previousYear->id]);

        // STEP 2 — Финансы ученика recognizes the new year; old debt stays
        // separate and is not merged into the (currently zero) new-year debt.
        $page = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));
        $page->assertOk();
        $page->assertSee($this->year->name);
        $page->assertSee($previousYear->name);
        $page->assertDontSee('Требуется зачисление');

        $byYear = app(StudentFinanceSummaryService::class)->summarizeByYear($this->student);
        $this->assertSame('0.00', $byYear['byYear'][$this->year->id]['net_outstanding']);
        $this->assertSame('10000.00', $byYear['byYear'][$previousYear->id]['net_outstanding']);

        // STEP 3 — Добавить услугу → representative Tuition issuance.
        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$this->fee->id], 'payment_type' => 'one_time',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $tuitionInvoice = Invoice::where('academic_year_id', $this->year->id)->sole();
        $this->assertSame($this->student->id, $tuitionInvoice->student_id);
        $previousInvoice->refresh();
        $this->assertSame('10000.00', $previousInvoice->remaining_amount, 'historical invoice untouched by the new-year issuance');

        // STEP 4 — Food through the unified picker's target: Charge & Collect.
        $calendar = AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $mealPlan = MealPlan::create(['name_ru' => 'Полный рацион', 'meal_type' => 'both', 'period' => 'daily', 'price' => '100.00', 'is_active' => true]);
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id,
            'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $food->id,
            'meal_plan_id' => $mealPlan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-09-01',
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '40.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $foodInvoice = Invoice::whereHas('items', fn ($q) => $q->where('fee_id', $food->id))->sole();
        $this->assertSame($this->year->id, $foodInvoice->academic_year_id);
        $this->assertSame('40.00', $foodInvoice->paid_amount, 'partial payment recorded as paid');
        $this->assertSame('60.00', $foodInvoice->remaining_amount, 'remainder correctly left outstanding');

        $coverage = ServiceCoverage::where('invoice_item_id', $foodInvoice->items->sole()->id)->sole();
        $this->assertSame($this->student->id, $coverage->student_id);

        // Historical Finance completely unaffected by any of this.
        $previousInvoice->refresh();
        $this->assertSame('10000.00', $previousInvoice->remaining_amount);
        $this->assertSame(1, Student::count());
    }
}
