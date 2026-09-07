<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Services\Finance\FinanceConfigurationReadinessService;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\TransportZoneOptionTypeNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * TransportZoneOptionTypeNormalizationService corrects ONLY
 * fee_prices.option_type on active Transport FeePrice rows, from the
 * known legacy value ('Район') to the canonical value ('zone'), for one
 * explicit AcademicYear. It never writes amount/option_value/
 * payment_period/dates, never creates or deletes a FeePrice row, and
 * never touches transport_routes/Food/Uniform/PaymentPlan data.
 */
class TransportZoneOptionTypeNormalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'Район';

    private const CANONICAL = 'zone';

    private function makeYear(string $name = '2026/2027', string $start = '2026-09-01', string $end = '2027-06-30'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => true]);
    }

    private function makeTransportFee(bool $isTestData = false): Fee
    {
        return Fee::create([
            'name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service',
            'payment_period' => null, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => $isTestData,
        ]);
    }

    /** @return array<int, FeePrice> */
    private function seedSixLegacyRows(Fee $fee, AcademicYear $year): array
    {
        $zones = [
            'Каусер, Мубарак 2, Интерконтиненталь' => ['yearly' => '13500.00', 'monthly' => '1500.00'],
            'Арабия, Мадарес, Шератон' => ['yearly' => '16200.00', 'monthly' => '1800.00'],
            'Мубарак 7, Эль-Хеляль, Эль-Ахья' => ['yearly' => '19800.00', 'monthly' => '2200.00'],
        ];
        $rows = [];
        foreach ($zones as $zone => $amounts) {
            foreach ($amounts as $period => $amount) {
                $rows[] = FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount, 'currency' => 'EGP',
                    'payment_period' => $period, 'option_type' => self::LEGACY, 'option_value' => $zone,
                    'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(),
                    'is_active' => true, 'change_reason' => 'Первоначальный импорт прайс-листа 2025/2026',
                ]);
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // 1. Six valid legacy rows normalize to zone.
    // ------------------------------------------------------------------
    public function test_six_valid_legacy_rows_normalize_to_zone(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(6, $result['legacy_found']);
        $this->assertSame(6, $result['eligible']);
        $this->assertSame(6, $result['normalized']);
        $this->assertTrue($result['applied']);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::CANONICAL)->count());
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count());
    }

    // ------------------------------------------------------------------
    // 2. Dry-run writes zero rows.
    // ------------------------------------------------------------------
    public function test_dry_run_writes_zero_rows(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: false);

        $this->assertSame(6, $result['eligible']);
        $this->assertFalse($result['applied']);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count());
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('option_type', self::CANONICAL)->count());
    }

    // ------------------------------------------------------------------
    // 3-7. Only option_type changes; every other field byte-identical.
    // ------------------------------------------------------------------
    public function test_only_option_type_changes_every_other_field_identical(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $rows = $this->seedSixLegacyRows($fee, $year);
        $before = collect($rows)->map(fn (FeePrice $p) => $p->fresh())->keyBy('id');

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        foreach ($before as $id => $original) {
            $after = FeePrice::find($id);
            $this->assertSame($id, $after->id, 'id preserved');
            $this->assertSame($fee->id, $after->fee_id, 'fee_id unchanged');
            $this->assertSame($year->id, $after->academic_year_id, 'academic_year_id unchanged');
            $this->assertSame((string) $original->getRawOriginal('amount'), (string) $after->getRawOriginal('amount'), 'amount numerically identical');
            $this->assertSame($original->option_value, $after->option_value, 'option_value unchanged');
            $this->assertSame($original->payment_period, $after->payment_period, 'payment_period unchanged');
            $this->assertSame($original->start_date->toDateString(), $after->start_date->toDateString(), 'start_date unchanged');
            $this->assertSame($original->end_date->toDateString(), $after->end_date->toDateString(), 'end_date unchanged');
            $this->assertSame($original->change_reason, $after->change_reason, 'change_reason unchanged');
            $this->assertTrue($after->is_active, 'is_active unchanged');
            $this->assertSame(self::CANONICAL, $after->option_type, 'option_type is the only field that changed');
        }
    }

    // ------------------------------------------------------------------
    // 8. Wrong AcademicYear untouched.
    // ------------------------------------------------------------------
    public function test_wrong_academic_year_untouched(): void
    {
        $targetYear = $this->makeYear('2026/2027', '2026-09-01', '2027-06-30');
        $otherYear = $this->makeYear('2025/2026', '2025-09-01', '2026-06-30');
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $otherYear);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($targetYear, apply: true);

        $this->assertSame(0, $result['legacy_found']);
        $this->assertSame(6, FeePrice::where('academic_year_id', $otherYear->id)->where('option_type', self::LEGACY)->count(), 'other year untouched');
    }

    // ------------------------------------------------------------------
    // 9. Different Fee untouched.
    // ------------------------------------------------------------------
    public function test_different_fee_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        $otherTransportFee = Fee::create(['name_ru' => 'Другой трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service', 'amount' => '0.00', 'is_active' => true, 'is_test_data' => true]);
        $otherPrice = FeePrice::create(['fee_id' => $otherTransportFee->id, 'academic_year_id' => $year->id, 'amount' => '100.00', 'currency' => 'EGP', 'option_type' => self::LEGACY, 'option_value' => 'Другая зона', 'payment_period' => 'monthly', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(self::LEGACY, $otherPrice->fresh()->option_type, 'a different Fee (even same category, test-data) is never touched — only the resolved operational Fee');
    }

    // ------------------------------------------------------------------
    // 10. Uniform/Food/Registration/Tuition FeePrices untouched.
    // ------------------------------------------------------------------
    public function test_other_category_feeprices_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $uniformFee = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $uniformPrice = FeePrice::create(['fee_id' => $uniformFee->id, 'academic_year_id' => $year->id, 'amount' => '500.00', 'currency' => 'EGP', 'item' => 'Майка', 'size' => '14', 'payment_period' => 'once', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        $foodFee = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $foodPrice = FeePrice::create(['fee_id' => $foodFee->id, 'academic_year_id' => $year->id, 'amount' => '170.00', 'currency' => 'EGP', 'option_type' => 'meal_plan', 'option_value' => '1', 'payment_period' => 'daily', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        $registrationFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'yearly', 'amount' => '0.00', 'is_active' => true]);
        $registrationPrice = FeePrice::create(['fee_id' => $registrationFee->id, 'academic_year_id' => $year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'payment_period' => 'yearly', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        $tuitionFee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $tuitionPrice = FeePrice::create(['fee_id' => $tuitionFee->id, 'academic_year_id' => $year->id, 'amount' => '30000.00', 'currency' => 'EGP', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame('Майка', $uniformPrice->fresh()->item);
        $this->assertSame('meal_plan', $foodPrice->fresh()->option_type);
        $this->assertSame('7000.00', number_format((float) $registrationPrice->fresh()->getRawOriginal('amount'), 2, '.', ''));
        $this->assertSame('30000.00', number_format((float) $tuitionPrice->fresh()->getRawOriginal('amount'), 2, '.', ''));
    }

    // ------------------------------------------------------------------
    // 11. Already-canonical zone rows unchanged.
    // ------------------------------------------------------------------
    public function test_already_canonical_zone_rows_unchanged(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $canonical = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '5000.00', 'currency' => 'EGP',
            'option_type' => self::CANONICAL, 'option_value' => 'Уже канонический', 'payment_period' => 'yearly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);
        $originalUpdatedAt = $canonical->updated_at;

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(0, $result['legacy_found']);
        $this->assertSame(1, $result['canonical_found']);
        $this->assertSame($originalUpdatedAt->toDateTimeString(), $canonical->fresh()->updated_at->toDateTimeString(), 'an already-canonical row must never be written to');
    }

    // ------------------------------------------------------------------
    // 12. Second apply is idempotent.
    // ------------------------------------------------------------------
    public function test_second_apply_is_idempotent(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        $service = app(TransportZoneOptionTypeNormalizationService::class);

        $service->normalize($year, apply: true);
        $second = $service->normalize($year, apply: true);

        $this->assertSame(0, $second['legacy_found']);
        $this->assertSame(0, $second['normalized']);
        $this->assertSame(6, $second['canonical_found']);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::CANONICAL)->count());
    }

    // ------------------------------------------------------------------
    // 13. Legacy + canonical row for same pricing identity => fail closed, zero writes.
    // ------------------------------------------------------------------
    public function test_legacy_plus_canonical_same_identity_fails_closed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        // A canonical row already exists for the SAME (option_value, payment_period)
        // identity as one of the legacy rows.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '13500.00', 'currency' => 'EGP',
            'option_type' => self::CANONICAL, 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'yearly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertNotSame([], $result['conflicts']);
        $this->assertFalse($result['applied']);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count(), 'zero writes — all 6 legacy rows remain untouched');
    }

    // ------------------------------------------------------------------
    // 14. Duplicate ambiguous legacy rows => fail closed, zero writes.
    // ------------------------------------------------------------------
    public function test_duplicate_ambiguous_legacy_rows_fail_closed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        // A second legacy row for the SAME (option_value, payment_period) as an existing one.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '14000.00', 'currency' => 'EGP',
            'option_type' => self::LEGACY, 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'yearly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertNotSame([], $result['conflicts']);
        $this->assertFalse($result['applied']);
        $this->assertSame(7, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count(), 'zero writes — all 7 legacy rows remain untouched');
    }

    // ------------------------------------------------------------------
    // 15. Blank option_value => fail closed.
    // ------------------------------------------------------------------
    public function test_blank_option_value_fails_closed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '1000.00', 'currency' => 'EGP',
            'option_type' => self::LEGACY, 'option_value' => null, 'payment_period' => 'monthly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertNotSame([], $result['unexpected_option_types']);
        $this->assertFalse($result['applied']);
        $this->assertSame(7, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count());
    }

    // ------------------------------------------------------------------
    // 16. Unexpected option_type => fail closed.
    // ------------------------------------------------------------------
    public function test_unexpected_option_type_fails_closed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '1000.00', 'currency' => 'EGP',
            'option_type' => 'district', 'option_value' => 'Странная зона', 'payment_period' => 'monthly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(1, $result['unexpected_found']);
        $this->assertFalse($result['applied']);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count(), 'zero writes — the known-good legacy rows are also left untouched when an unrelated row is unexpected');
    }

    // ------------------------------------------------------------------
    // 17. Explicit year-id works with duplicate AcademicYear names.
    // ------------------------------------------------------------------
    public function test_explicit_year_id_works_with_duplicate_academic_year_names(): void
    {
        $canonical = $this->makeYear('2026/2027', '2026-09-01', '2027-06-30');
        $duplicate = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => false]);
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $canonical);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($canonical, apply: true);

        $this->assertSame($canonical->id, $result['academic_year_id']);
        $this->assertNotSame($duplicate->id, $result['academic_year_id']);
        $this->assertSame(6, $result['normalized']);

        Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => $duplicate->id, '--dry-run' => true]);
        $this->assertStringContainsString((string) $duplicate->id, Artisan::output());
    }

    // ------------------------------------------------------------------
    // 18-21. transport_routes / meal_plans / uniform_products / payment_plans untouched.
    // ------------------------------------------------------------------
    public function test_transport_routes_mealplans_uniformproducts_paymentplans_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        DB::table('transport_routes')->insert(['name' => 'Фикстура маршрута', 'created_at' => now(), 'updated_at' => now()]);
        $mealPlan = MealPlan::create(['name_ru' => 'Фикстура питания', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY, 'price' => '100.00', 'is_active' => true]);
        DB::table('uniform_products')->insert(['name_ru' => 'Майка', 'category' => 'uniform', 'size' => '14', 'price' => '500.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $paymentPlan = PaymentPlan::create(['name_ru' => 'Фикстура плана', 'is_active' => true, 'sort_order' => 0]);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(1, DB::table('transport_routes')->count());
        $this->assertSame(1, MealPlan::count());
        $this->assertTrue($mealPlan->refresh()->is_active);
        $this->assertSame(1, DB::table('uniform_products')->count());
        $this->assertSame(1, PaymentPlan::count());
        $this->assertTrue($paymentPlan->refresh()->is_active);
    }

    // ------------------------------------------------------------------
    // 22. Transaction rollback leaves zero partial normalization on simulated mid-write failure.
    // ------------------------------------------------------------------
    public function test_transaction_rollback_leaves_zero_partial_normalization(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'update') && str_contains($query->sql, 'fee_prices')) {
                throw new RuntimeException('simulated failure mid-transaction');
            }
        });

        $thrown = null;
        try {
            app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'expected the simulated failure to propagate');
        $this->assertStringContainsString('simulated failure', $thrown->getMessage());
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count(), 'a mid-transaction failure must leave zero partial normalization');
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('option_type', self::CANONICAL)->count());
    }

    // ------------------------------------------------------------------
    // 23. InvoiceCalculationService resolves the corrected zone tariff after normalization.
    // ------------------------------------------------------------------
    public function test_invoice_calculation_service_resolves_corrected_zone_tariff(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $calculation = app(InvoiceCalculationService::class)->calculate(
            items: [['fee_id' => $fee->id, 'quantity' => 1, 'option_type' => 'zone', 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'monthly']],
            pricingDate: $year->start_date->toDateString(),
            academicYearId: $year->id,
        );

        $line = collect($calculation['line_items'])->first();
        $this->assertSame('1500.00', number_format((float) $line['amount'], 2, '.', ''));
    }

    // ------------------------------------------------------------------
    // 24. FinanceConfigurationReadinessService recognizes the normalized pricing matrix.
    // ------------------------------------------------------------------
    public function test_readiness_service_recognizes_normalized_pricing(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $before = app(FinanceConfigurationReadinessService::class)->forFee($fee, $year);
        $this->assertFalse($before['ready']);
        $this->assertStringContainsString('транспортной зоны', (string) $before['reason']);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $after = app(FinanceConfigurationReadinessService::class)->forFee($fee, $year);
        // Still not ready overall — transport_routes is empty (a separate,
        // unrelated gate this corrective deliberately does not touch) —
        // but the reason must now be the ROUTE-metadata reason, proving the
        // zone-pricing half of readiness was recognized as fixed.
        $this->assertFalse($after['ready']);
        $this->assertStringContainsString('маршрут', (string) $after['reason']);
        $this->assertStringNotContainsString('Нет тарифа ни для одной транспортной зоны', (string) $after['reason']);
    }

    // ------------------------------------------------------------------
    // 25. Quick Registration Transport zone data can see the normalized zones/periods.
    // ------------------------------------------------------------------
    public function test_quick_registration_can_see_normalized_zones(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $zones = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->where('option_type', 'zone')->pluck('option_value')->unique()->values();

        $this->assertCount(3, $zones);
        $this->assertTrue($zones->contains('Каусер, Мубарак 2, Интерконтиненталь'));
    }

    // ------------------------------------------------------------------
    // 26. Mixed billing semantics remain unchanged.
    // ------------------------------------------------------------------
    public function test_mixed_billing_semantics_unchanged(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        $registrationFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'yearly', 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $registrationFee->id, 'academic_year_id' => $year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'payment_period' => 'yearly', 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $calculation = app(InvoiceCalculationService::class)->calculate(
            items: [
                ['fee_id' => $registrationFee->id, 'quantity' => 1],
                ['fee_id' => $fee->id, 'quantity' => 1, 'option_type' => 'zone', 'option_value' => 'Арабия, Мадарес, Шератон', 'payment_period' => 'yearly'],
            ],
            pricingDate: $year->start_date->toDateString(),
            academicYearId: $year->id,
        );

        $this->assertCount(2, $calculation['line_items']);
        $total = collect($calculation['line_items'])->sum(fn ($l) => (float) $l['amount']);
        $this->assertEquals(7000 + 16200, $total);
    }

    // ------------------------------------------------------------------
    // 27. Inactive historical 'Район' rows remain untouched.
    // ------------------------------------------------------------------
    public function test_inactive_historical_legacy_rows_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        $inactive = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '9999.00', 'currency' => 'EGP',
            'option_type' => self::LEGACY, 'option_value' => 'Историческая неактивная зона', 'payment_period' => 'yearly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => false,
        ]);

        $result = app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame(6, $result['legacy_found'], 'the inactive row is never counted as an active-scope legacy row');
        $this->assertSame(self::LEGACY, $inactive->fresh()->option_type, 'inactive historical row left exactly as-is');
        $this->assertFalse($inactive->fresh()->is_active);
    }

    // ------------------------------------------------------------------
    // 28-29. No FeePrice rows created/deleted; IDs identical before/after.
    // ------------------------------------------------------------------
    public function test_no_feeprice_rows_created_or_deleted_ids_identical(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $rows = $this->seedSixLegacyRows($fee, $year);
        $idsBefore = collect($rows)->pluck('id')->sort()->values()->all();
        $countBefore = FeePrice::count();

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        $this->assertSame($countBefore, FeePrice::count(), 'no row created or deleted');
        $idsAfter = FeePrice::where('fee_id', $fee->id)->pluck('id')->sort()->values()->all();
        $this->assertSame($idsBefore, $idsAfter, 'exact same ids before and after');
    }

    // ------------------------------------------------------------------
    // 30. change_reason remains unchanged (covered inline in test 3-7 too;
    // isolated here for direct traceability against the requested list).
    // ------------------------------------------------------------------
    public function test_change_reason_remains_unchanged(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $rows = $this->seedSixLegacyRows($fee, $year);

        app(TransportZoneOptionTypeNormalizationService::class)->normalize($year, apply: true);

        foreach ($rows as $row) {
            $this->assertSame('Первоначальный импорт прайс-листа 2025/2026', $row->fresh()->change_reason);
        }
    }

    // ------------------------------------------------------------------
    // Command-level: explicit --year-id is required, never a name.
    // ------------------------------------------------------------------
    public function test_command_refuses_to_run_without_year_id(): void
    {
        $exitCode = Artisan::call('finance:normalize-transport-zone-option-type', ['--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_command_refuses_nonexistent_year_id(): void
    {
        $exitCode = Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => 999999, '--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_command_refuses_both_dry_run_and_apply_together(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $exitCode = Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => $year->id, '--dry-run' => true, '--apply' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count());
    }

    public function test_command_defaults_to_dry_run_when_neither_mode_is_passed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => $year->id]);

        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count(), 'omitting both flags must never silently apply');
    }

    public function test_command_apply_with_explicit_year_id_normalizes(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);

        $exitCode = Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => $year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::CANONICAL)->count());
    }

    public function test_command_fails_and_writes_nothing_on_conflict(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeTransportFee();
        $this->seedSixLegacyRows($fee, $year);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '13500.00', 'currency' => 'EGP',
            'option_type' => self::CANONICAL, 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'yearly',
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $exitCode = Artisan::call('finance:normalize-transport-zone-option-type', ['--year-id' => $year->id, '--apply' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(6, FeePrice::where('fee_id', $fee->id)->where('option_type', self::LEGACY)->count());
    }
}
