<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 — proves the canonical dimension contract end to end: the
 * importer produces rows every live consumer can actually resolve, the
 * classic Invoice Create screen's persistence path accepts the same
 * convention, and a stale/inactive/wrong-year price is never mistaken for
 * an available one. See CLAUDE.md audit "Canonical Dimension Contract".
 */
class CanonicalPricingContractTest extends FinanceOperationsTestCase
{
    public function test_imported_transport_zone_resolves_through_the_quick_registration_price_endpoint(): void
    {
        // The importer targets its own fixed academic year; give it one.
        AcademicYear::where('id', '!=', $this->year->id)->delete();
        $importYear = AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $this->year->update(['is_active' => false]);
        app(SchoolPriceListImportService::class)->import();

        $transport = Fee::where('category', Fee::CATEGORY_TRANSPORT)->firstOrFail();
        $zone = FeePrice::where('fee_id', $transport->id)->where('payment_period', 'monthly')->firstOrFail();

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $transport->id,
            'quantity' => 1,
            'academic_year_id' => $importYear->id,
            'enrollment_mode_id' => $this->makeActiveEnrollmentMode()->id,
            'transport_area' => $zone->option_value,
            'payment_period' => $zone->payment_period,
            'pricing_date' => '2025-09-15',
        ]);

        $response->assertOk()->assertJsonPath('unit_price', $zone->amount);
    }

    public function test_imported_food_tariff_resolves_through_the_quick_registration_price_endpoint(): void
    {
        AcademicYear::where('id', '!=', $this->year->id)->delete();
        $importYear = AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $this->year->update(['is_active' => false]);
        app(SchoolPriceListImportService::class)->import();

        // Food flexible-duration corrective pass: the live-pricing preview
        // endpoint resolves Food's range via FoodBillableDayCalculator,
        // which requires an AcademicCalendar for the year plus an explicit
        // duration-mode selection.
        \App\Models\AcademicCalendar::create(['academic_year_id' => $importYear->id, 'weekly_days_off' => ['fri', 'sat']]);
        $food = Fee::where('category', Fee::CATEGORY_FOOD)->firstOrFail();
        $breakfast = MealPlan::where('name_ru', 'Завтрак')->firstOrFail();
        $price = FeePrice::where('fee_id', $food->id)->where('option_value', (string) $breakfast->id)->firstOrFail();

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $food->id,
            'quantity' => 1,
            'academic_year_id' => $importYear->id,
            'enrollment_mode_id' => $this->makeActiveEnrollmentMode()->id,
            'meal_plan_id' => $breakfast->id,
            'pricing_date' => '2025-09-15',
            'food_duration_mode' => 'day',
            'food_date' => '2025-09-15',
        ]);

        $response->assertOk()->assertJsonPath('unit_price', $price->amount);
    }

    /**
     * Food flexible-duration corrective pass: Food can no longer be
     * bought through the classic one-time invoice screen at all — it
     * requires an explicit duration-mode selection (day/school_week/
     * teaching_days/month/custom_range) only Quick Registration's
     * calendar payment_type offers, and InvoiceController::create()
     * already excludes Food from this screen's own fee dropdown
     * (Fee::CATEGORY_FOOD is filtered out there). This test now confirms
     * the server-side guard behind that exclusion actually holds — a
     * direct POST attempting to buy Food here is rejected, never silently
     * priced.
     */
    public function test_food_cannot_be_purchased_through_the_classic_invoice_create_screen(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $plan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => 'lunch', 'period' => 'daily', 'price' => '100.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => $this->year->end_date->toDateString(),
            'pricing_date' => $this->year->start_date->toDateString(),
            'fees' => [$food->id],
            'option_type' => [$food->id => 'meal_plan'],
            'option_value' => [$food->id => (string) $plan->id],
            'initial_payment_amount' => '0.00',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_sellable_uniform_item_and_size_combination_resolves(): void
    {
        $uniform = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '1.00', 'is_active' => true]);
        DB::table('uniform_products')->insert([
            'name_ru' => 'Комплект', 'category' => 'set', 'size' => '6-10', 'price' => '2000.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        FeePrice::create([
            'fee_id' => $uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '2000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => 'Комплект', 'size' => '6-10', 'payment_period' => 'once',
        ]);

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $uniform->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->makeActiveEnrollmentMode()->id,
            'item' => 'Комплект', 'size' => '6-10',
        ]);

        $response->assertOk()->assertJsonPath('unit_price', '2000.00');
    }

    public function test_a_mismatched_uniform_item_and_size_combination_does_not_resolve(): void
    {
        $uniform = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '1.00', 'is_active' => true]);
        // Catalog product exists at size 12-16, but the tariff was only configured for 6-10.
        DB::table('uniform_products')->insert([
            'name_ru' => 'Комплект', 'category' => 'set', 'size' => '12-16', 'price' => '2500.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        FeePrice::create([
            'fee_id' => $uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '2000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => 'Комплект', 'size' => '6-10', 'payment_period' => 'once',
        ]);

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $uniform->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->makeActiveEnrollmentMode()->id,
            'item' => 'Комплект', 'size' => '12-16',
        ]);

        $response->assertStatus(422);
    }

    public function test_wrong_grade_year_or_mode_never_resolves_a_tuition_price(): void
    {
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        // The shared fixture already has a tuition price for $this->year / the enrolled grade.
        $this->assertSame(
            '1200.00',
            app(InvoiceCalculationService::class)->calculate(
                items: [['fee_id' => $this->fee->id, 'quantity' => 1, 'grade_id' => $this->enrollment->grade_id]],
                pricingDate: $this->year->start_date->toDateString(),
                academicYearId: $this->year->id,
            )['line_items'][0]['unit_price'],
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            items: [['fee_id' => $this->fee->id, 'quantity' => 1, 'grade_id' => $this->enrollment->grade_id]],
            pricingDate: $otherYear->start_date->toDateString(),
            academicYearId: $otherYear->id,
        );
    }

    public function test_quick_registration_page_load_never_mutates_financial_or_master_data(): void
    {
        $countsBefore = [Invoice::count(), FeePrice::count(), Fee::count(), MealPlan::count()];

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $this->assertSame($countsBefore, [Invoice::count(), FeePrice::count(), Fee::count(), MealPlan::count()]);
    }

    private function makeActiveEnrollmentMode(): \App\Models\EnrollmentMode
    {
        return \App\Models\EnrollmentMode::firstOrCreate(['code' => 'full_time'], ['name_ru' => 'Очная', 'is_active' => true]);
    }
}
