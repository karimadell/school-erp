<?php

namespace Tests\Feature\Finance;

use App\Models\BillingBatch;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Finance\MassBillingEligibilityService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Support\Collection;

class MassBillingPreviewTest extends MassBillingTestCase
{
    private function preview(BillingBatch $batch): array
    {
        return app(MassBillingPreviewService::class)->preview($batch);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(array $result): Collection
    {
        return collect($result['rows']);
    }

    private function row(array $result, Student $student): array
    {
        return $this->rows($result)->firstWhere('student_id', $student->id);
    }

    public function test_preview_creates_no_invoices_and_persists_previewed_snapshot(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $this->enrolledStudent($this->classA, suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);

        $result = $this->preview($batch);

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(2, $result['eligible_count']);

        $batch->refresh();
        $this->assertSame(BillingBatch::STATUS_PREVIEWED, $batch->status);
        $this->assertSame(2, $batch->selected_count);
        $this->assertSame(2, $batch->eligible_count);
        $this->assertNotNull($batch->previewed_at);
        $this->assertIsArray($batch->preview_snapshot);
        $this->assertCount(2, $batch->preview_snapshot);
    }

    public function test_inactive_withdrawn_enrollment_is_skipped_but_remains_visible(): void
    {
        $active = $this->enrolledStudent($this->classA, suffix: 'A1');
        $withdrawn = $this->enrolledStudent($this->classA, status: 'withdrawn', suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);

        $result = $this->preview($batch);

        $this->assertTrue($this->row($result, $active)['eligible']);
        $this->assertFalse($this->row($result, $withdrawn)['eligible']);
        $this->assertSame(MassBillingPreviewService::SKIP_ENROLLMENT_WITHDRAWN, $this->row($result, $withdrawn)['skip_reason']);
        $this->assertSame(1, $result['eligible_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(2, $result['selected_count']);
    }

    public function test_graduated_and_transferred_enrollments_get_specific_skip_reasons(): void
    {
        $graduated = $this->enrolledStudent($this->classA, status: 'graduated', suffix: 'G');
        $transferred = $this->enrolledStudent($this->classA, status: 'transferred', suffix: 'T');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);

        $result = $this->preview($batch);

        $this->assertSame(MassBillingPreviewService::SKIP_ENROLLMENT_GRADUATED, $this->row($result, $graduated)['skip_reason']);
        $this->assertSame(MassBillingPreviewService::SKIP_ENROLLMENT_TRANSFERRED, $this->row($result, $transferred)['skip_reason']);
        $this->assertSame(0, $result['eligible_count']);
    }

    public function test_a_genuinely_unconfigured_grade_is_skipped_as_no_tariff(): void
    {
        // $this->tuition has a tariff for $this->grade only — a student in
        // a grade with zero tuition tariffs must still be skipped, proving
        // the sole-candidate prepayment exemption never widens to "any
        // tariff for this fee, regardless of grade".
        $otherGrade = Grade::forceCreate(['name' => '2 КЛАСС', 'stage_id' => $this->stage->id, 'level' => 2]);
        $otherClass = SchoolClass::create(['grade_id' => $otherGrade->id, 'code' => '2-А', 'name_ru' => '2-А', 'name_ar' => '2-A', 'is_active' => true]);
        $student = $this->enrolledStudent($otherClass, grade: $otherGrade, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$otherClass->id], issueDate: '2026-09-01');

        $result = $this->preview($batch);

        $this->assertFalse($this->row($result, $student)['eligible']);
        $this->assertSame(MassBillingPreviewService::SKIP_NO_TARIFF, $this->row($result, $student)['skip_reason']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_sole_same_year_tariff_is_usable_even_before_its_own_start_date(): void
    {
        // Phase 4A.2 canonical rule: academic_year_id is the primary
        // ownership boundary for a tariff, not the calendar date — a batch
        // may bill for the academic year before its sole tariff's own
        // start_date (2026-08-01), exactly like a parent prepaying would.
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], issueDate: '2026-05-01');

        $result = $this->preview($batch);

        $this->assertTrue($this->row($result, $student)['eligible']);
    }

    public function test_two_same_year_tariffs_are_disambiguated_by_the_issue_date(): void
    {
        FeePrice::create(['fee_id' => $this->tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->grade->id,
            'amount' => '1100.00', 'currency' => 'EGP', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31',
            'change_reason' => 'Льготный тариф при ранней оплате.', 'is_active' => true]);
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], issueDate: '2026-06-15');

        $result = $this->preview($batch);

        $this->assertTrue($this->row($result, $student)['eligible']);
    }

    public function test_an_ambiguous_pre_window_date_with_multiple_same_year_tariffs_is_skipped(): void
    {
        // Two same-year, same-grade tariffs (early bird + regular) with the
        // issue date before either window — must fail clearly, not guess.
        FeePrice::create(['fee_id' => $this->tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->grade->id,
            'amount' => '1100.00', 'currency' => 'EGP', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31',
            'change_reason' => 'Льготный тариф при ранней оплате.', 'is_active' => true]);
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], issueDate: '2026-04-01');

        $result = $this->preview($batch);

        $this->assertFalse($this->row($result, $student)['eligible']);
        $this->assertSame(MassBillingPreviewService::SKIP_NO_TARIFF, $this->row($result, $student)['skip_reason']);
    }

    public function test_food_is_skipped_as_not_supported_by_mass_billing(): void
    {
        // Final independent review, MEDIUM finding: Mass Billing has no
        // duration-mode selection (no per-student day/school_week/
        // teaching_days/month/custom_range choice), so Food must always
        // be rejected here fail-closed — even when a fully active Food
        // Fee with a real daily tariff exists, proving the rule is
        // unconditional rather than a fallback for missing pricing.
        $mealPlan = MealPlan::create([
            'name_ru' => 'Полный рацион', 'meal_type' => 'both', 'period' => 'daily',
            'price' => '100.00', 'is_active' => true,
        ]);
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => Fee::PERIOD_DAILY, 'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id,
            'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], fee: $food);

        $result = $this->preview($batch);

        $this->assertFalse($this->row($result, $student)['eligible']);
        $this->assertSame(MassBillingEligibilityService::SKIP_FOOD_NOT_SUPPORTED, $this->row($result, $student)['skip_reason']);
    }

    public function test_food_rejection_reason_is_shown_in_russian(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], fee: $food);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.preview', $batch))
            ->assertRedirect(route('dashboard.finance.mass-billing.show', $batch));

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch))
            ->assertOk()
            ->assertSee(__('mass_billing.skip_reasons.food_not_supported'));
    }

    public function test_non_food_service_remains_eligible_under_mass_billing(): void
    {
        // Regression guard: the Food fail-closed rule must not have
        // disabled Mass Billing globally — the smallest already-covered
        // non-Food category (Tuition) must remain fully eligible under
        // an otherwise-identical setup.
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);

        $result = $this->preview($batch);

        $this->assertTrue($this->row($result, $student)['eligible']);
        $this->assertNull($this->row($result, $student)['skip_reason']);
    }

    public function test_registration_fee_duplicate_is_skipped(): void
    {
        $regFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $regFee->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'start_date' => '2026-05-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $alreadyCharged = $this->enrolledStudent($this->classA, suffix: 'Dup');
        $fresh = $this->enrolledStudent($this->classA, suffix: 'New');
        $this->seedRegistrationInvoice($alreadyCharged, $regFee);

        $batch = $this->makeBatch(classIds: [$this->classA->id], fee: $regFee);

        $result = $this->preview($batch);

        $this->assertFalse($this->row($result, $alreadyCharged)['eligible']);
        $this->assertSame(MassBillingPreviewService::SKIP_REGISTRATION_DUPLICATE, $this->row($result, $alreadyCharged)['skip_reason']);
        $this->assertTrue($this->row($result, $fresh)['eligible']);
    }

    public function test_server_side_tariff_is_resolved_per_student(): void
    {
        $gradeB = Grade::forceCreate(['name' => '2 КЛАСС', 'stage_id' => $this->stage->id, 'level' => 2]);
        FeePrice::create(['fee_id' => $this->tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $gradeB->id, 'amount' => '900.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $studentA = $this->enrolledStudent($this->classA, suffix: 'A1');           // grade 1 → 1200
        $studentB = $this->enrolledStudent($this->classA, grade: $gradeB, suffix: 'B2'); // grade 2 → 900

        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $result = $this->preview($batch);

        $this->assertSame('1200.00', $this->row($result, $studentA)['unit_price']);
        $this->assertSame('900.00', $this->row($result, $studentB)['unit_price']);
    }

    public function test_preview_totals_and_counts_are_server_computed(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $this->enrolledStudent($this->classA, suffix: 'A2');
        $this->enrolledStudent($this->classA, status: 'withdrawn', suffix: 'A3');

        $batch = $this->makeBatch(classIds: [$this->classA->id], quantity: 2);
        $result = $this->preview($batch);

        // 2 eligible × 1200 × qty 2 = 4800.00; 1 skipped.
        $this->assertSame(3, $result['selected_count']);
        $this->assertSame(2, $result['eligible_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(2, $result['expected_invoice_count']);
        $this->assertSame('4800.00', $result['expected_total_amount']);
    }

    public function test_store_creates_draft_batch_with_persisted_targets(): void
    {
        $included = $this->makeStudent('Inc');
        $excluded = $this->enrolledStudent($this->classA, suffix: 'Exc');

        $this->actingAs($this->accountant)->post(route('dashboard.finance.mass-billing.store'), [
            'academic_year_id' => $this->year->id, 'fee_id' => $this->tuition->id, 'quantity' => 1,
            'issue_date' => '2026-09-01', 'due_date' => '2027-01-01', 'target_mode' => BillingBatch::TARGET_MODE_CLASSES,
            'class_ids' => [$this->classA->id], 'include_student_ids' => [$included->id], 'exclude_student_ids' => [$excluded->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $batch = BillingBatch::sole();
        $this->assertSame(BillingBatch::STATUS_DRAFT, $batch->status);
        $this->assertNotNull($batch->uuid);
        $this->assertSame([$this->classA->id], $batch->classTargets->pluck('class_id')->all());
        $this->assertEqualsCanonicalizing([$included->id], $batch->includedStudentIds()->all());
        $this->assertEqualsCanonicalizing([$excluded->id], $batch->excludedStudentIds()->all());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_price_override_fields_are_rejected(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');

        $this->actingAs($this->accountant)->post(route('dashboard.finance.mass-billing.store'), [
            'academic_year_id' => $this->year->id, 'fee_id' => $this->tuition->id, 'quantity' => 1,
            'issue_date' => '2026-09-01', 'due_date' => '2027-01-01', 'target_mode' => BillingBatch::TARGET_MODE_CLASSES,
            'class_ids' => [$this->classA->id], 'unit_price' => '5.00', 'override_price' => '5.00',
        ])->assertSessionHasErrors(['unit_price', 'override_price']);

        $this->assertDatabaseCount('billing_batches', 0);
    }

    public function test_preview_page_shows_russian_labels(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $this->enrolledStudent($this->classA, status: 'withdrawn', suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.preview', $batch))
            ->assertRedirect(route('dashboard.finance.mass-billing.show', $batch));

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch))
            ->assertOk()
            ->assertSee('Предварительный просмотр')
            ->assertSee('Будут выставлены')
            ->assertSee('Пропущено')
            ->assertSee('Ученик отчислен.');

        $this->assertDatabaseCount('invoices', 0);
    }

    private function seedRegistrationInvoice(Student $student, Fee $fee): void
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '7000.00', 'total_amount' => '7000.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '7000.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru,
            'unit_price' => '7000.00', 'quantity' => 1, 'amount' => '7000.00', 'paid_amount' => '0.00', 'remaining_amount' => '7000.00',
        ]);
    }
}
