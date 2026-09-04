<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-Premium-UI corrective pass — Decision 1.
 *
 * Quick Registration's Tuition payment-period dropdown must surface every
 * period the Fee is ALLOWED to bill under (FeeBillingPeriod — the Phase 2B
 * canonical source), not only periods a FeePrice row physically exists
 * for. Quarterly must appear and correctly derive monthly x 3 even with
 * zero explicit quarterly FeePrice rows; an explicit quarterly FeePrice
 * must still win over derivation when one exists; a period the Fee does
 * NOT allow must never appear regardless of what FeePrice rows exist.
 */
class QuickRegistrationQuarterlyExposureTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected AcademicYear $year;

    protected Grade $grade;

    protected Stage $stage;

    protected SchoolClass $class;

    protected EnrollmentMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);
    }

    private function tuitionFee(array $allowedPeriods): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        foreach ($allowedPeriods as $period) {
            $fee->billingPeriods()->create(['billing_period' => $period]);
        }

        return $fee;
    }

    private function monthlyPrice(Fee $fee, string $amount): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly',
            'grade_id' => $this->grade->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    /**
     * Extracts only the specific per-Fee "services[N][payment_period]"
     * <select> element (N is always 0 — every test here creates exactly
     * one Fee). Scoping this tightly matters: the page ALSO carries a
     * separate, pre-existing, unrelated invoice-level "billing_period"
     * <select> (payment-plan-fields.blade.php — a hardcoded monthly/
     * quarterly/yearly cadence choice for the whole invoice) that is
     * always present regardless of any Fee's own allowed periods and is
     * out of scope for this corrective pass — a same-page-text-anywhere
     * assertion would false-fail/false-pass against it.
     */
    private function tuitionPeriodSelectHtml(string $html): string
    {
        $pattern = '/<select name="services\[0\]\[payment_period\]"[^>]*>.*?<\/select>/s';
        $this->assertMatchesRegularExpression($pattern, $html, 'services[0][payment_period] select not found in the response.');
        preg_match($pattern, $html, $matches);

        return $matches[0];
    }

    public function test_quarterly_appears_when_allowed_even_without_an_explicit_quarterly_feeprice(): void
    {
        $fee = $this->tuitionFee(['monthly', 'quarterly']);
        $this->monthlyPrice($fee, '400.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $response->assertOk();

        $select = $this->tuitionPeriodSelectHtml($response->getContent());
        $this->assertStringContainsString('value="quarterly"', $select);
        $this->assertStringContainsString('Ежеквартально', $select);
    }

    public function test_derived_quarterly_amount_equals_monthly_times_three(): void
    {
        $fee = $this->tuitionFee(['monthly', 'quarterly']);
        $this->monthlyPrice($fee, '400.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'payment_period' => 'quarterly',
            'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->mode->id, 'registration_date' => '2026-08-15',
        ]);

        $response->assertOk();
        $this->assertSame('1200.00', $response->json('amount'));
        $this->assertSame('1200.00', $response->json('unit_price'));
    }

    public function test_explicit_quarterly_tariff_overrides_derived_price(): void
    {
        $fee = $this->tuitionFee(['monthly', 'quarterly']);
        $this->monthlyPrice($fee, '400.00');
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly',
            'grade_id' => $this->grade->id, 'amount' => '1050.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'payment_period' => 'quarterly',
            'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->mode->id, 'registration_date' => '2026-08-15',
        ]);

        $response->assertOk();
        // Explicit tariff (1050.00) must win over the derived 400*3=1200.00.
        $this->assertSame('1050.00', $response->json('amount'));
    }

    public function test_disallowed_periods_do_not_appear_in_the_dropdown(): void
    {
        $fee = $this->tuitionFee(['monthly']);
        $this->monthlyPrice($fee, '400.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $response->assertOk();

        $select = $this->tuitionPeriodSelectHtml($response->getContent());
        $this->assertStringNotContainsString('value="quarterly"', $select);
        $this->assertStringNotContainsString('value="yearly"', $select);
    }
}
