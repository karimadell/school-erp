<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Services\Finance\FinanceConfigurationReadinessService;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Food Phase 4B corrective (owner-approved, supersedes the former
 * "3 a-la-carte legacy Food names are permanently excluded from MealPlan"
 * decision): proves the three legacy Food names — Суп, Второе блюдо,
 * Напиток — become real, operational MealPlan records, joining the
 * already-migrated Комплексное питание/Завтрак/Обед for six total
 * selectable Food choices, using the SAME finance:uat-master-data-repair
 * machinery, the SAME InvoiceCalculationService pricing engine, and the
 * SAME FinanceConfigurationReadinessService/finance:readiness-audit
 * generic numeric-id classification — nothing name-specific was added
 * anywhere in the consumer paths.
 *
 * These tests complement (not duplicate) the exhaustive per-command
 * coverage already in UatMasterDataRepairTest and
 * AcademicYear20262027PriceCorrectiveTest: this file focuses on the
 * cross-cutting proofs the migration itself must satisfy — readiness
 * audit before/after, historical InvoiceItem safety, Quick
 * Registration + Unified Collection exposing all six choices through
 * the unchanged generic mechanism, and the amount-conflict abort guard.
 */
class FoodPhase4BLegacyMealPlanMigrationTest extends FinanceOperationsTestCase
{
    private const LEGACY_NAMES = ['Суп', 'Второе блюдо', 'Напиток'];

    private const ALL_SIX_NAMES = [
        'Комплексное питание', 'Завтрак', 'Обед', 'Суп', 'Второе блюдо', 'Напиток',
    ];

    private Fee $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCanonicalRegistrationModeCatalog();
        AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
    }

    /** Seeds all 6 Food names as raw legacy FeePrice.option_value text — the exact pre-migration production shape. */
    private function seedAllSixLegacyRows(): array
    {
        $amounts = [
            'Комплексное питание' => '170.00', 'Завтрак' => '70.00', 'Обед' => '100.00',
            'Суп' => '20.00', 'Второе блюдо' => '80.00', 'Напиток' => '10.00',
        ];
        $rows = [];
        foreach ($amounts as $name => $amount) {
            $rows[$name] = FeePrice::create([
                'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
                'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
                'option_type' => 'meal_plan', 'option_value' => $name, 'payment_period' => 'daily',
            ]);
        }

        return $rows;
    }

    private function migrate(): void
    {
        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => $this->year->name, '--apply' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());
    }

    // ----- 1/2. Readiness audit: legacy textual values are flagged before
    //            migration and correctly resolved after ------------------

    public function test_readiness_audit_flags_all_three_legacy_names_before_migration(): void
    {
        $this->seedAllSixLegacyRows();

        Artisan::call('finance:readiness-audit', ['--year' => $this->year->name]);
        $output = Artisan::output();

        foreach (self::LEGACY_NAMES as $name) {
            $this->assertStringContainsString($name, $output);
        }
        $this->assertStringContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
    }

    public function test_readiness_audit_no_longer_flags_the_three_legacy_names_after_migration(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();

        Artisan::call('finance:readiness-audit', ['--year' => $this->year->name]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
    }

    /** FinanceReadinessAudit::foodDimensionIssue() is unchanged (already fully generic) — a genuinely textual value elsewhere must still be caught. */
    public function test_readiness_audit_still_catches_a_genuinely_textual_food_value_after_migration(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();

        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => '55.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => 'Совершенно новое блюдо', 'payment_period' => 'daily',
        ]);

        Artisan::call('finance:readiness-audit', ['--year' => $this->year->name]);
        $output = Artisan::output();

        $this->assertStringContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
        $this->assertStringContainsString('Совершенно новое блюдо', $output);
    }

    public function test_finance_configuration_readiness_service_reports_food_ready_after_migration(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();

        $readiness = app(FinanceConfigurationReadinessService::class)->forFee($this->food, $this->year);

        $this->assertTrue($readiness['ready']);
    }

    // ----- 3. Every CRITICAL FINANCIAL INVARIANT field is preserved for
    //          each of the 3 legacy rows — only option_value changes ------

    public function test_every_unrelated_fee_price_field_is_byte_identical_before_and_after_migration(): void
    {
        $rows = $this->seedAllSixLegacyRows();
        $invariantFields = ['id', 'fee_id', 'academic_year_id', 'grade_id', 'grade_group', 'payment_period', 'option_type', 'is_active', 'currency', 'amount'];
        $snapshot = fn (FeePrice $row) => array_merge($row->only($invariantFields), [
            'start_date' => $row->start_date->toDateString(),
            'end_date' => $row->end_date->toDateString(),
        ]);
        $before = collect(self::LEGACY_NAMES)->mapWithKeys(fn ($name) => [$name => $snapshot($rows[$name])]);

        $this->migrate();

        foreach (self::LEGACY_NAMES as $name) {
            $row = FeePrice::findOrFail($rows[$name]->id);
            $this->assertSame($before[$name], $snapshot($row), "{$name}: every field except option_value must be unchanged");
            $this->assertNotSame($name, $row->option_value, "{$name}: option_value must have changed away from the legacy text");
            $this->assertTrue(is_numeric($row->option_value), "{$name}: option_value must now be numeric");
        }
    }

    // ----- 4. Ambiguous candidates (amount_conflict) abort before any write --

    public function test_apply_aborts_with_no_writes_when_a_legacy_name_has_conflicting_amounts(): void
    {
        $this->seedAllSixLegacyRows();
        // A second 'Суп' row at a different amount — an unresolvable ambiguity.
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => '25.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => 'Суп', 'payment_period' => 'daily',
        ]);
        $optionValuesBefore = FeePrice::where('fee_id', $this->food->id)->pluck('option_value', 'id')->all();

        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => $this->year->name, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'a conflicted apply must fail closed');
        $this->assertStringContainsString('ABORTED', $output);
        $this->assertStringContainsString('Суп', $output);
        $this->assertSame(0, MealPlan::count(), 'no MealPlan may be created when any Food name is ambiguous');
        $this->assertSame($optionValuesBefore, FeePrice::where('fee_id', $this->food->id)->pluck('option_value', 'id')->all(), 'no option_value may be rewritten when the apply aborts');
    }

    // ----- 5. Historical InvoiceItem safety: an already-issued invoice's
    //          item is untouched by the later master-data migration -------

    public function test_a_historical_invoice_item_issued_before_migration_is_unaffected_by_it(): void
    {
        $rows = $this->seedAllSixLegacyRows();
        $legacyPrice = $rows['Второе блюдо'];

        // Historical issuance predates Phase 4B: the invoice is issued
        // directly against the legacy textual option_value row, exactly as
        // production data looked before this migration existed.
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-30', 'pricing_date' => '2026-09-01',
            'items' => [[
                'fee_id' => $this->food->id, 'quantity' => 1,
                'payment_period' => 'daily', 'option_type' => 'meal_plan',
                'option_value' => 'Второе блюдо',
                'food_duration_mode' => 'custom_range',
                'food_range_start' => '2026-09-01', 'food_range_end' => '2026-09-30',
            ]],
            'payment_type' => 'calendar',
        ], $this->accountant);

        $item = InvoiceItem::where('invoice_id', $invoice->id)->sole();
        $itemBefore = $item->only(['id', 'invoice_id', 'fee_id', 'amount', 'description']);

        $this->migrate();

        $itemAfter = InvoiceItem::findOrFail($item->id);
        $this->assertSame($itemBefore, $itemAfter->only(['id', 'invoice_id', 'fee_id', 'amount', 'description']), 'a historical InvoiceItem snapshot must never be rewritten by a later master-data migration');

        // The underlying FeePrice row itself only had option_value repointed — its amount, the thing the historical invoice was actually priced from, is untouched.
        $this->assertSame($legacyPrice->amount, FeePrice::findOrFail($legacyPrice->id)->amount);
    }

    // ----- 6. All six Food names resolve through the SAME pricing engine,
    //          matching their pre-migration FeePrice amounts exactly -------

    public function test_all_six_migrated_names_resolve_through_invoice_calculation_service_at_their_original_amount(): void
    {
        $rows = $this->seedAllSixLegacyRows();
        $this->migrate();

        $plans = MealPlan::pluck('id', 'name_ru');
        $this->assertCount(6, $plans);

        foreach (self::ALL_SIX_NAMES as $name) {
            $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2026-09-01', 'pricing_date' => '2026-09-01',
                'items' => [[
                    'fee_id' => $this->food->id, 'quantity' => 1,
                    'payment_period' => 'daily', 'option_type' => 'meal_plan',
                    'option_value' => (string) $plans[$name],
                    'food_duration_mode' => 'day', 'food_date' => '2026-09-01',
                ]],
                'payment_type' => 'calendar',
            ], $this->accountant, idempotencyKey: (string) Str::uuid());

            $expectedAmount = $rows[$name]->amount;
            $this->assertSame($expectedAmount, $invoice->items->sole()->amount, "{$name}: invoice item amount must equal the pre-migration FeePrice amount, never invented");
        }
    }

    // ----- 7. Quick Registration exposes all six choices, generically ------

    public function test_quick_registration_offers_all_six_food_choices_after_migration(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();

        $html = $this->actingAs($this->accountant)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        foreach (self::ALL_SIX_NAMES as $name) {
            $this->assertStringContainsString($name, $html, "{$name} must be offered as a selectable meal plan");
        }
    }

    public function test_quick_registration_price_preview_resolves_a_newly_migrated_legacy_name(): void
    {
        $rows = $this->seedAllSixLegacyRows();
        $this->migrate();
        $plan = MealPlan::where('name_ru', 'Напиток')->sole();

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $this->food->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'grade_id' => $this->enrollment->grade_id, 'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
            'pricing_date' => '2026-09-01',
            'meal_plan_id' => $plan->id,
            'food_duration_mode' => 'day', 'food_date' => '2026-09-01',
        ]);

        $response->assertOk();
        $this->assertSame($rows['Напиток']->amount, $response->json('amount'));
    }

    // ----- 8. Unified Collection exposes all six choices, generically ------

    public function test_unified_collection_offers_all_six_food_choices_after_migration(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();

        $html = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.unified-collection.create', $this->student))
            ->assertOk()->getContent();

        foreach (self::ALL_SIX_NAMES as $name) {
            $this->assertStringContainsString($name, $html, "{$name} must be offered as a selectable meal plan");
        }
    }

    // ----- 9. The 3 already-migrated names keep working exactly as before
    //          (no regression from extending the map to all 6) -------------

    public function test_the_original_three_names_are_unaffected_by_extending_the_migration_to_all_six(): void
    {
        $rows = $this->seedAllSixLegacyRows();
        $this->migrate();

        foreach (['Комплексное питание' => MealPlan::TYPE_BOTH, 'Завтрак' => MealPlan::TYPE_BREAKFAST, 'Обед' => MealPlan::TYPE_LUNCH] as $name => $expectedType) {
            $plan = MealPlan::where('name_ru', $name)->sole();
            $this->assertSame($expectedType, $plan->meal_type);
            $row = FeePrice::findOrFail($rows[$name]->id);
            $this->assertSame((string) $plan->id, $row->option_value);
            $this->assertSame($rows[$name]->amount, $row->amount);
        }
    }

    // ----- 10. Idempotent rerun: a second --apply after the migration makes
    //           no further writes to any of the six Food rows --------------

    public function test_a_second_apply_after_migration_makes_no_further_food_writes(): void
    {
        $this->seedAllSixLegacyRows();
        $this->migrate();
        $after = FeePrice::where('fee_id', $this->food->id)->orderBy('id')->get()->toArray();
        $planCountAfter = MealPlan::count();

        Artisan::call('finance:uat-master-data-repair', ['--year' => $this->year->name, '--apply' => true]);

        $this->assertSame($after, FeePrice::where('fee_id', $this->food->id)->orderBy('id')->get()->toArray());
        $this->assertSame($planCountAfter, MealPlan::count());
    }
}
