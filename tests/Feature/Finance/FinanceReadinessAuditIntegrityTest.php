<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4A.3 — the audit's own sections (D/H/I) and the live readiness
 * simulation (L/M) must never contradict each other. Before this phase,
 * Section H/I correctly showed zero usable meal plans / zero uniform
 * products, while Section L (FinanceConfigurationReadinessService) still
 * reported food/uniform READY — because pricing resolvability alone was
 * being treated as readiness. These tests lock in that L/M now agree with
 * what H/I already showed, and that the Tuition/Externat mixing bug in
 * section F is fixed.
 */
class FinanceReadinessAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private Stage $stage;
    private Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
    }

    private function price(Fee $fee, array $overrides = []): FeePrice
    {
        return FeePrice::create(array_merge([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
        ], $overrides));
    }

    public function test_food_readiness_agrees_with_section_h_for_legacy_textual_data(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '300.00', 'is_active' => true]);
        $this->price($food, ['option_type' => 'meal_plan', 'option_value' => 'Напиток']);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('zero usable', $output);
        // Section L/M must not claim Food is ready when H already shows zero usable plans.
        preg_match('/food.*(READY|NOT READY)/i', $output, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('NOT READY', $matches[0]);
    }

    public function test_uniform_readiness_agrees_with_section_i_for_zero_products(): void
    {
        $uniform = Fee::create(['name_ru' => 'Футболка', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '200.00', 'is_active' => true]);
        $this->price($uniform, ['item' => 'Футболка', 'size' => 'M']);
        // Deliberately zero uniform_products rows.

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('uniform_products catalog is completely empty', $output);
        $this->assertMatchesRegularExpression('/Uniform.*NOT READY/s', $output);
    }

    public function test_uniform_readiness_is_ready_when_a_matching_active_product_exists(): void
    {
        $uniform = Fee::create(['name_ru' => 'Футболка', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '200.00', 'is_active' => true]);
        $this->price($uniform, ['item' => 'Футболка', 'size' => 'M']);
        DB::table('uniform_products')->insert(['name_ru' => 'Футболка', 'category' => 'shirt', 'size' => 'M', 'price' => 200, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/Uniform.*READY(?! \/ )/s', $output);
    }

    public function test_transport_reports_pricing_ready_route_metadata_missing_when_routes_are_empty(): void
    {
        $transport = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '500.00', 'is_active' => true]);
        $this->price($transport, ['option_type' => 'zone', 'option_value' => 'Зона 1']);
        // Deliberately zero transport_routes rows.

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PRICING READY / ROUTE METADATA MISSING', $output);
    }

    public function test_tuition_and_externat_matrices_are_reported_separately_and_never_mixed(): void
    {
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '40500.00', 'is_active' => true]);
        $this->price($tuition, ['amount' => '40500.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        $externat = Fee::create(['name_ru' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => '25600.00', 'is_active' => true]);
        $this->price($externat, ['amount' => '25600.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('F. Tuition coverage matrix', $output);
        $this->assertStringContainsString('F2. Externat (tuition_external) coverage matrix', $output);

        // Extract just the F..F2 matrix (ordinary tuition) and the F2..G
        // matrix (externat) as distinct substrings, so amounts that also
        // legitimately appear earlier in section C's fee catalog dump can't
        // produce a false match.
        $tuitionMatrixStart = strpos($output, 'F. Tuition coverage matrix');
        $externatMatrixStart = strpos($output, 'F2. Externat');
        $transportSectionStart = strpos($output, 'G. Transport');
        $this->assertNotFalse($tuitionMatrixStart);
        $this->assertNotFalse($externatMatrixStart);
        $this->assertNotFalse($transportSectionStart);

        $tuitionMatrix = substr($output, $tuitionMatrixStart, $externatMatrixStart - $tuitionMatrixStart);
        $externatMatrix = substr($output, $externatMatrixStart, $transportSectionStart - $externatMatrixStart);

        $this->assertStringContainsString('40500', $tuitionMatrix);
        $this->assertStringNotContainsString('25600', $tuitionMatrix);
        $this->assertStringContainsString('25600', $externatMatrix);
        $this->assertStringNotContainsString('40500', $externatMatrix);
    }

    public function test_ordinary_tuition_readiness_is_unaffected_by_a_missing_externat_tariff(): void
    {
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '40500.00', 'is_active' => true]);
        $this->price($tuition, ['amount' => '40500.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        // No Fee::CATEGORY_TUITION_EXTERNAL row at all.

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/tuition\s*\|.*READY/i', $output);
    }

    public function test_the_audit_completes_read_only_with_all_gaps_present_simultaneously(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '300.00', 'is_active' => true]);
        $this->price($food, ['option_type' => 'meal_plan', 'option_value' => 'Обед']);
        $uniform = Fee::create(['name_ru' => 'Футболка', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '200.00', 'is_active' => true]);
        $this->price($uniform, ['item' => 'Футболка', 'size' => 'M']);
        $transport = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '500.00', 'is_active' => true]);
        $this->price($transport, ['option_type' => 'zone', 'option_value' => 'Зона 1']);
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '40500.00', 'is_active' => true]);
        $this->price($tuition, ['amount' => '40500.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        $externat = Fee::create(['name_ru' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => '25600.00', 'is_active' => true]);
        $this->price($externat, ['amount' => '25600.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);

        $countsBefore = [
            Fee::count(), FeePrice::count(), MealPlan::count(),
            DB::table('uniform_products')->count(), DB::table('transport_routes')->count(),
        ];

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Audit complete. No data was created, updated, or deleted.', Artisan::output());
        $this->assertSame($countsBefore, [
            Fee::count(), FeePrice::count(), MealPlan::count(),
            DB::table('uniform_products')->count(), DB::table('transport_routes')->count(),
        ]);
    }
}
