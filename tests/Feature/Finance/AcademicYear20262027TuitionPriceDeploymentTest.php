<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\AcademicYear20262027TuitionPriceDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AcademicYear20262027TuitionPriceDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private Fee $tuition;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $this->year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $this->tuition = Fee::create([
            'name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00',
            'is_active' => true, 'is_test_data' => false,
        ]);
        foreach (['monthly', 'yearly'] as $period) {
            FeeBillingPeriod::create(['fee_id' => $this->tuition->id, 'billing_period' => $period]);
        }
        foreach ($this->modeNames() as $code => $name) {
            EnrollmentMode::create(['code' => $code, 'name_ru' => $name, 'is_active' => true]);
        }
    }

    public function test_default_command_is_true_read_only_and_plans_32_creates(): void
    {
        $before = $this->allTableSnapshots();

        $this->artisan('finance:deploy-tuition-prices-2026-2027')
            ->expectsOutputToContain('DRY-RUN / ZERO WRITES')
            ->expectsOutputToContain('32')
            ->expectsOutputToContain('Dry-run complete: zero writes')
            ->assertSuccessful();

        $this->assertSame($before, $this->allTableSnapshots());
        $this->assertSame(0, FeePrice::count());
        $plan = $this->service()->plan();
        $this->assertSame(['CREATE' => 32, 'IDENTICAL' => 0, 'CONFLICT' => 0], $plan['totals']);
        $this->assertCount(32, $plan['rows']);
        $this->assertCount(32, collect($plan['rows'])->map(fn ($row) => $row['option_value'].'|'.$row['grade_group'].'|'.$row['payment_period'])->unique());
    }

    public function test_canonical_spaced_academic_year_resolves_without_assuming_numeric_id(): void
    {
        $this->assertNotSame(1, $this->year->id);

        $plan = $this->service()->plan();

        $this->assertSame($this->year->id, $plan['academic_year']['id']);
        $this->assertSame('2026 / 2027', $plan['academic_year']['name']);
    }

    public function test_apply_creates_and_verifies_the_exact_approved_matrix(): void
    {
        $result = $this->service()->apply();

        $this->assertSame(32, $result['created_count']);
        $this->assertSame(32, $result['verified_count']);
        $this->assertSame(32, FeePrice::count());
        $this->assertSame(0, FeePrice::where('payment_period', 'quarterly')->count());
        $this->assertSame(32, FeePrice::where('option_type', 'enrollment_mode')->count());
        $this->assertSame(32, FeePrice::where('currency', 'EGP')->whereDate('start_date', '2026-09-01')->whereDate('end_date', '2027-05-31')->where('is_active', true)->count());
        $this->assertSame(0, FeePrice::whereNotNull('grade_id')->orWhereNotNull('item')->orWhereNotNull('size')->count());

        foreach ($this->expectedAmounts() as $key => $amount) {
            [$mode, $group, $period] = explode('|', $key);
            $price = FeePrice::where('option_value', $mode)->where('grade_group', $group)->where('payment_period', $period)->sole();
            $this->assertSame($amount, $price->amount, $key);
        }

        $external = FeePrice::where('option_value', 'external')->get();
        $this->assertCount(2, $external);
        $this->assertSame(['1–4 классы'], $external->pluck('grade_group')->unique()->values()->all());
        foreach (['Подготовительный класс', '5–6 классы', '7–8 классы', '9–11 классы'] as $group) {
            $this->assertFalse(FeePrice::where('option_value', 'external')->where('grade_group', $group)->exists());
        }
    }

    public function test_fully_identical_rerun_performs_zero_writes(): void
    {
        $this->service()->apply();
        $timestamps = FeePrice::orderBy('id')->get()->mapWithKeys(fn (FeePrice $price) => [$price->id => $price->updated_at->toISOString()])->all();
        $result = $this->service()->apply();

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(['CREATE' => 0, 'IDENTICAL' => 32, 'CONFLICT' => 0], $result['totals']);
        $this->assertSame(32, FeePrice::count());
        $this->assertSame($timestamps, FeePrice::orderBy('id')->get()->mapWithKeys(fn (FeePrice $price) => [$price->id => $price->updated_at->toISOString()])->all());
    }

    public function test_partially_identical_state_creates_only_missing_rows(): void
    {
        $plan = $this->service()->plan();
        foreach (array_slice($plan['rows'], 0, 7) as $row) {
            FeePrice::create($this->attributes($row));
        }

        $result = $this->service()->apply();

        $this->assertSame(25, $result['created_count']);
        $this->assertSame(7, $result['totals']['IDENTICAL']);
        $this->assertSame(32, FeePrice::count());
    }

    public function test_conflicting_amount_aborts_without_new_rows(): void
    {
        $this->seedConflict(['amount' => '9999.00']);
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_conflicting_dates_abort_without_new_rows(): void
    {
        $this->seedConflict(['start_date' => '2026-10-01']);
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_conflicting_currency_aborts_without_new_rows(): void
    {
        $row = $this->service()->plan()['rows'][0];
        DB::table('fee_prices')->insert($this->attributes($row, ['currency' => 'USD']) + ['created_at' => now(), 'updated_at' => now()]);
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_inactive_same_scope_aborts_without_new_rows(): void
    {
        $this->seedConflict(['is_active' => false]);
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_duplicate_same_scope_rows_abort_without_writes(): void
    {
        $row = $this->service()->plan()['rows'][0];
        FeePrice::create($this->attributes($row));
        FeePrice::create($this->attributes($row));
        $this->assertApplyFails();
        $this->assertSame(2, FeePrice::count());
    }

    public function test_overlapping_active_row_aborts_without_new_rows(): void
    {
        $this->seedConflict(['start_date' => '2026-08-15', 'end_date' => '2026-12-31']);
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_legacy_mode_alias_aborts_without_new_rows(): void
    {
        $row = $this->service()->plan()['rows'][0];
        FeePrice::create($this->attributes($row, ['option_type' => 'Форма', 'option_value' => 'Очная форма обучения']));
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_canonical_option_type_with_translated_mode_value_is_ambiguous(): void
    {
        $row = $this->service()->plan()['rows'][0];
        FeePrice::create($this->attributes($row, ['option_value' => 'Очная форма обучения']));
        $this->assertConflictLeavesOnlyExistingRow();
    }

    public function test_unapproved_external_group_and_quarterly_rows_abort(): void
    {
        $row = $this->service()->plan()['rows'][0];
        FeePrice::create($this->attributes($row, [
            'option_value' => 'external', 'grade_group' => '5–6 классы', 'payment_period' => 'quarterly',
        ]));
        $this->assertApplyFails();
        $this->assertSame(1, FeePrice::count());
    }

    public function test_missing_academic_year_fails_with_zero_writes(): void
    {
        $this->year->delete();
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_duplicate_canonical_academic_year_fails_with_zero_writes(): void
    {
        AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => false,
        ]);
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_effective_date_outside_academic_year_fails(): void
    {
        $this->year->update(['start_date' => '2026-10-01']);
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_zero_or_multiple_operational_tuition_fees_fail(): void
    {
        $this->tuition->update(['is_active' => false]);
        $this->assertApplyFails();
        $this->tuition->update(['is_active' => true]);
        Fee::create(['name_ru' => 'Другая', 'category' => Fee::CATEGORY_TUITION, 'amount' => 0, 'is_active' => true, 'is_test_data' => false]);
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_test_and_legacy_external_fees_are_not_selected(): void
    {
        Fee::create(['name_ru' => 'Тест', 'category' => Fee::CATEGORY_TUITION, 'amount' => 0, 'is_active' => true, 'is_test_data' => true]);
        Fee::create(['name_ru' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => 0, 'is_active' => true, 'is_test_data' => false]);
        $this->assertSame($this->tuition->id, $this->service()->plan()['fee']['id']);
    }

    public function test_missing_enrollment_mode_fails_with_zero_writes(): void
    {
        EnrollmentMode::where('code', 'family')->delete();
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_inactive_enrollment_mode_is_resolved_but_not_changed(): void
    {
        EnrollmentMode::where('code', 'family')->update(['is_active' => false]);
        $this->service()->apply();
        $this->assertFalse(EnrollmentMode::where('code', 'family')->sole()->is_active);
    }

    public function test_monthly_unsupported_fails_with_zero_writes(): void
    {
        FeeBillingPeriod::where('billing_period', 'monthly')->delete();
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_yearly_unsupported_fails_with_zero_writes(): void
    {
        FeeBillingPeriod::where('billing_period', 'yearly')->delete();
        $this->assertApplyFails();
        $this->assertSame(0, FeePrice::count());
    }

    public function test_generic_tuition_rows_are_reported_and_preserved_unchanged(): void
    {
        $generic = FeePrice::create([
            'fee_id' => $this->tuition->id, 'academic_year_id' => $this->year->id,
            'grade_group' => '1–4 классы', 'payment_period' => 'monthly', 'amount' => '1234.00',
            'currency' => 'EGP', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31',
            'option_type' => null, 'option_value' => null, 'is_active' => true,
        ]);
        $before = (array) DB::table('fee_prices')->find($generic->id);
        $plan = $this->service()->plan();
        $this->assertSame(1, $plan['generic_rows']);
        $this->service()->apply();
        $this->assertSame($before, (array) DB::table('fee_prices')->find($generic->id));
        $this->assertSame(33, FeePrice::count());
    }

    public function test_historical_finance_records_remain_byte_for_byte_unchanged(): void
    {
        $basis = FeePrice::create($this->attributes($this->service()->plan()['rows'][0]));
        $user = User::factory()->create();
        $student = Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'status' => Student::STATUS_ACTIVE]);
        $cash = CashAccount::create(['name' => 'Историческая касса', 'type' => CashAccount::TYPE_CASH, 'balance' => 0, 'is_active' => true]);
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $student->name,
            'subtotal_amount' => 4500, 'total_amount' => 4500, 'discount_amount' => 0, 'paid_amount' => 4500,
            'remaining_amount' => 0, 'status' => Invoice::STATUS_PAID, 'cash_account_id' => $cash->id,
            'paid_at' => now(), 'created_by' => $user->id,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $this->tuition->id, 'description' => 'Историческое обучение',
            'unit_price' => 4500, 'quantity' => 1, 'amount' => 4500, 'paid_amount' => 4500, 'remaining_amount' => 0,
        ]);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $cash->id, 'amount' => 4500,
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $user->id,
        ]);
        ServiceCoverage::create([
            'student_id' => $student->id, 'fee_id' => $this->tuition->id, 'invoice_item_id' => $item->id,
            'fee_price_id' => $basis->id, 'coverage_start' => '2026-09-01', 'coverage_end' => '2026-09-30',
            'billing_unit' => 'monthly', 'payment_period' => 'monthly', 'original_unit_price' => 4500,
            'created_by' => $user->id,
        ]);
        $before = $this->financeSnapshots();

        $this->service()->apply();

        $this->assertSame($before, $this->financeSnapshots());
    }

    public function test_exception_after_inserts_rolls_back_the_entire_matrix(): void
    {
        $service = new class extends AcademicYear20262027TuitionPriceDeploymentService
        {
            protected function beforeFinalVerification(): void
            {
                throw new RuntimeException('forced verification failure');
            }
        };

        try {
            $service->apply();
            $this->fail('Expected forced verification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced verification failure', $exception->getMessage());
        }

        $this->assertSame(0, FeePrice::count());
    }

    private function service(): AcademicYear20262027TuitionPriceDeploymentService
    {
        return app(AcademicYear20262027TuitionPriceDeploymentService::class);
    }

    private function assertConflictLeavesOnlyExistingRow(): void
    {
        $plan = $this->service()->plan();
        $this->assertSame(1, $plan['totals']['CONFLICT']);
        $this->assertApplyFails();
        $this->assertSame(1, FeePrice::count());
    }

    private function assertApplyFails(): void
    {
        try {
            $this->service()->apply();
            $this->fail('Expected guarded deployment to fail.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    private function seedConflict(array $overrides): void
    {
        $row = $this->service()->plan()['rows'][0];
        FeePrice::create($this->attributes($row, $overrides));
    }

    private function attributes(array $row, array $overrides = []): array
    {
        return array_replace(collect($row)->except('status')->all(), $overrides);
    }

    private function modeNames(): array
    {
        return [
            'full_time' => 'Очная форма обучения',
            'family' => 'Семейная форма обучения',
            'no_enrollment' => 'Без зачисления',
            'external' => 'Экстернат',
        ];
    }

    private function expectedAmounts(): array
    {
        $standard = [
            'Подготовительный класс' => ['monthly' => '4500.00', 'yearly' => '40500.00'],
            '1–4 классы' => ['monthly' => '5500.00', 'yearly' => '49500.00'],
            '5–6 классы' => ['monthly' => '6500.00', 'yearly' => '58500.00'],
            '7–8 классы' => ['monthly' => '7500.00', 'yearly' => '67500.00'],
            '9–11 классы' => ['monthly' => '9000.00', 'yearly' => '81000.00'],
        ];
        $expected = [];
        foreach (['full_time', 'family', 'no_enrollment'] as $mode) {
            foreach ($standard as $group => $periods) {
                foreach ($periods as $period => $amount) {
                    $expected["{$mode}|{$group}|{$period}"] = $amount;
                }
            }
        }
        $expected['external|1–4 классы|monthly'] = '3200.00';
        $expected['external|1–4 классы|yearly'] = '25600.00';

        return $expected;
    }

    private function allTableSnapshots(): array
    {
        return [
            'academic_years' => DB::table('academic_years')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'fees' => DB::table('fees')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'fee_billing_periods' => DB::table('fee_billing_periods')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'enrollment_modes' => DB::table('enrollment_modes')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'fee_prices' => DB::table('fee_prices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function financeSnapshots(): array
    {
        return collect(['invoices', 'invoice_items', 'invoice_payments', 'service_coverages'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
