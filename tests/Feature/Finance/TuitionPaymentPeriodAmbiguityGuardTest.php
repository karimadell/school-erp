<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Models\BillingRunItem;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\MassBillingExecutionService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finance P1 corrective: InvoiceCalculationService::dimensionalCandidates()
 * previously left the payment_period column completely unfiltered whenever
 * the caller's own selection didn't supply one — so once a Fee/scope had
 * more than one payment_period-tagged tariff (monthly + yearly is a
 * genuinely supported configuration, see QuarterlyDerivedPricingTest),
 * selectAmongCandidates() picked between them by date-window/id order
 * alone, silently resolving whichever happened to sort first. Proven (Mass
 * Billing discovery pass) to flip the resolved amount purely by FeePrice
 * insertion order, with zero error and zero skip — worse than a crash,
 * since nothing signals anything went wrong.
 *
 * The new guard mirrors $hasModePrices' exact "never guess" architecture
 * for a different dimension: evaluated only among candidates whose own
 * window actually covers the pricing date, and only when more than one
 * DISTINCT payment_period value (including NULL as one of the possible
 * values) remains among those. A single distinct value — generic-only,
 * or exactly one period-specific tariff — is never ambiguous and continues
 * to resolve exactly as before.
 */
class TuitionPaymentPeriodAmbiguityGuardTest extends MassBillingTestCase
{
    private function service(): InvoiceCalculationService
    {
        return app(InvoiceCalculationService::class);
    }

    private function tuition(string $suffix = ''): Fee
    {
        return Fee::create(['name_ru' => "Обучение $suffix", 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
    }

    private function price(Fee $fee, ?string $paymentPeriod, string $amount, ?string $modeCode = null, ?Grade $grade = null, ?AcademicYear $year = null, string $start = '2026-08-01', string $end = '2027-06-30'): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => ($year ?? $this->year)->id, 'grade_id' => ($grade ?? $this->grade)->id,
            'payment_period' => $paymentPeriod, 'option_type' => $modeCode ? 'enrollment_mode' : null, 'option_value' => $modeCode,
            'amount' => $amount, 'currency' => 'EGP', 'start_date' => $start, 'end_date' => $end, 'is_active' => true,
        ]);
    }

    private function item(Fee $fee, ?string $paymentPeriod = null, ?int $modeId = null, ?Grade $grade = null): array
    {
        return array_filter([
            'fee_id' => $fee->id, 'grade_id' => ($grade ?? $this->grade)->id, 'payment_period' => $paymentPeriod, 'enrollment_mode_id' => $modeId,
        ], fn ($value) => $value !== null);
    }

    private function calculate(array $item, ?AcademicYear $year = null, string $pricingDate = '2026-09-10'): array
    {
        return $this->service()->calculate([$item], academicYearId: ($year ?? $this->year)->id, pricingDate: $pricingDate);
    }

    // 1. Generic-only (NULL period) + blank selection resolves normally —
    // today's actual production shape, unaffected.
    public function test_generic_null_period_price_resolves_normally_with_blank_selection(): void
    {
        $fee = $this->tuition('generic');
        $this->price($fee, null, '1200.00');

        $result = $this->calculate($this->item($fee));

        $this->assertSame('1200.00', $result['subtotal']);
    }

    // 2. Exactly ONE non-null payment_period tariff + blank selection is
    // NOT ambiguous — must continue to resolve, never regress into a
    // false failure.
    public function test_single_non_null_period_price_resolves_normally_with_blank_selection(): void
    {
        $fee = $this->tuition('single-period');
        $this->price($fee, 'yearly', '11000.00');

        $result = $this->calculate($this->item($fee));

        $this->assertSame('11000.00', $result['subtotal']);
    }

    // 3. Two distinct payment_period tariffs + blank selection must fail
    // loudly — proven insertion-order-independent (this is the confirmed
    // defect), with zero financial writes.
    public function test_two_distinct_period_prices_with_blank_selection_fails_loudly_monthly_first(): void
    {
        $fee = $this->tuition('ambiguous-a');
        $this->price($fee, 'monthly', '1000.00');
        $this->price($fee, 'yearly', '11000.00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от периода оплаты');

        $this->calculate($this->item($fee));
    }

    public function test_two_distinct_period_prices_with_blank_selection_fails_loudly_yearly_first(): void
    {
        $fee = $this->tuition('ambiguous-b');
        $this->price($fee, 'yearly', '11000.00');
        $this->price($fee, 'monthly', '1000.00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от периода оплаты');

        $this->calculate($this->item($fee));
    }

    // 4. A generic NULL-period row coexisting with a period-specific row
    // must also fail loudly — the generic row's presence is never an
    // excuse to silently pick either one.
    public function test_generic_and_period_specific_coexisting_with_blank_selection_fails_loudly(): void
    {
        $fee = $this->tuition('generic-plus-specific');
        $this->price($fee, null, '900.00');
        $this->price($fee, 'monthly', '1000.00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от периода оплаты');

        $this->calculate($this->item($fee));
    }

    // 5. An explicitly supplied payment_period always resolves its own
    // exact amount, regardless of FeePrice creation order.
    public function test_explicit_monthly_and_yearly_resolve_exact_amounts_regardless_of_creation_order(): void
    {
        $fee = $this->tuition('explicit');
        $this->price($fee, 'yearly', '11000.00');
        $this->price($fee, 'monthly', '1000.00');

        $monthly = $this->calculate($this->item($fee, 'monthly'));
        $this->assertSame('1000.00', $monthly['subtotal']);

        $yearly = $this->calculate($this->item($fee, 'yearly'));
        $this->assertSame('11000.00', $yearly['subtotal']);
    }

    // 6. Mode + period interaction — full matrix of 4 distinct rows.
    public function test_full_time_monthly_and_external_yearly_resolve_their_own_exact_prices(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $fee = $this->tuition('mode-and-period');
        $this->price($fee, 'monthly', '4000.00', 'full_time');
        $this->price($fee, 'yearly', '40500.00', 'full_time');
        $this->price($fee, 'monthly', '2500.00', 'external');
        $this->price($fee, 'yearly', '25600.00', 'external');

        $fullTimeMonthly = $this->calculate($this->item($fee, 'monthly', $this->mode->id));
        $this->assertSame('4000.00', $fullTimeMonthly['subtotal']);

        $externalYearly = $this->calculate($this->item($fee, 'yearly', $external->id));
        $this->assertSame('25600.00', $externalYearly['subtotal']);
    }

    // Blank mode where mode is required takes priority over the new period
    // guard — the existing PR #80 message, not this PR's new one.
    public function test_blank_mode_where_mode_required_still_triggers_existing_mode_guard(): void
    {
        $fee = $this->tuition('mode-priority');
        $this->price($fee, 'monthly', '4000.00', 'full_time');
        $this->price($fee, 'yearly', '40500.00', 'full_time');
        $this->price($fee, 'monthly', '2500.00', 'external');
        $this->price($fee, 'yearly', '25600.00', 'external');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от формы обучения');

        $this->calculate($this->item($fee));
    }

    // A VALID, resolvable mode with a blank period, where multiple periods
    // exist for that exact mode, must trigger the NEW period guard — mode
    // resolution succeeds first, narrowing to that mode's own rows, which
    // then reveal the period ambiguity.
    public function test_valid_mode_with_blank_period_and_multiple_periods_triggers_new_period_guard(): void
    {
        $fee = $this->tuition('mode-then-period');
        $this->price($fee, 'monthly', '4000.00', 'full_time');
        $this->price($fee, 'yearly', '40500.00', 'full_time');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от периода оплаты');

        $this->calculate($this->item($fee, null, $this->mode->id));
    }

    // 7. Grade isolation: an ambiguous scope in one grade must not affect a
    // different grade's own unambiguous resolution.
    public function test_grade_isolation_for_period_ambiguity_guard(): void
    {
        $otherGrade = Grade::forceCreate(['name' => '2 КЛАСС', 'stage_id' => $this->stage->id, 'level' => 2]);
        $fee = $this->tuition('grade-isolation');
        $this->price($fee, 'monthly', '1000.00', grade: $this->grade);
        $this->price($fee, 'yearly', '11000.00', grade: $this->grade);
        $this->price($fee, null, '9500.00', grade: $otherGrade);

        $result = $this->calculate($this->item($fee, grade: $otherGrade));
        $this->assertSame('9500.00', $result['subtotal']);

        $this->expectException(ValidationException::class);
        $this->calculate($this->item($fee, grade: $this->grade));
    }

    // 8. Academic-year isolation: an ambiguous scope in another year must
    // not affect the target year's own unambiguous resolution.
    public function test_academic_year_isolation_for_period_ambiguity_guard(): void
    {
        $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $fee = $this->tuition('year-isolation');
        $this->price($fee, 'monthly', '1000.00', year: $otherYear, start: '2025-08-01', end: '2026-06-30');
        $this->price($fee, 'yearly', '11000.00', year: $otherYear, start: '2025-08-01', end: '2026-06-30');
        $this->price($fee, null, '9500.00');

        $result = $this->calculate($this->item($fee));
        $this->assertSame('9500.00', $result['subtotal']);
    }

    // 9. Effective-date isolation: an EXPIRED monthly tariff coexisting with
    // a currently-active yearly tariff must not create a false ambiguity —
    // only the yearly one actually applies on the pricing date.
    public function test_expired_monthly_and_active_yearly_do_not_create_false_ambiguity(): void
    {
        $fee = $this->tuition('date-isolation');
        $this->price($fee, 'monthly', '1000.00', start: '2025-08-01', end: '2026-06-30'); // expired before 2026-09-10
        $this->price($fee, 'yearly', '11000.00', start: '2026-08-01', end: '2027-06-30'); // currently active

        $result = $this->calculate($this->item($fee));

        $this->assertSame('11000.00', $result['subtotal']);
    }

    // 10. Quarterly derivation (a DIFFERENT, explicit-'quarterly' mechanism)
    // must remain unaffected — re-verified here directly against the new
    // guard's exact code path, alongside QuarterlyDerivedPricingTest's own
    // full coverage (run separately as a regression suite).
    public function test_explicit_quarterly_derivation_is_unaffected_by_the_new_guard(): void
    {
        $fee = $this->tuition('quarterly');
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $this->price($fee, 'monthly', '1000.00');

        $result = $this->calculate($this->item($fee, 'quarterly'));

        $this->assertSame('3000.00', $result['subtotal']);
    }

    // 11. Zero-write proof for the ambiguous-period failure path, through
    // the real HTTP-adjacent InvoiceIssuanceService boundary (not just the
    // calculator in isolation).
    public function test_ambiguous_period_failure_commits_zero_financial_writes(): void
    {
        $fee = $this->tuition('zero-write');
        $this->price($fee, 'monthly', '1000.00');
        $this->price($fee, 'yearly', '11000.00');
        $student = $this->enrolledStudent();

        $before = [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ];

        try {
            app(\App\Services\Finance\InvoiceIssuanceService::class)->issue($student, [
                'student_id' => $student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-10',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
                'payment_type' => 'one_time',
            ], $this->accountant);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('тариф зависит от периода оплаты', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame($before, [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ]);
    }

    // 12. Mass Billing safety regression (the original discovery's own
    // reproduction case): through the REAL execution path, an ambiguous
    // scope must skip gracefully — never issue an invoice with an
    // arbitrary 1000 or 11000 amount, in EITHER FeePrice creation order.
    public function test_mass_billing_skips_ambiguous_period_fee_gracefully_monthly_first(): void
    {
        $this->assertMassBillingSkipsAmbiguousFee(['monthly', 'yearly']);
    }

    public function test_mass_billing_skips_ambiguous_period_fee_gracefully_yearly_first(): void
    {
        $this->assertMassBillingSkipsAmbiguousFee(['yearly', 'monthly']);
    }

    private function assertMassBillingSkipsAmbiguousFee(array $creationOrder): void
    {
        $fee = $this->tuition('mass-billing-'.implode('-', $creationOrder));
        $amounts = ['monthly' => '1000.00', 'yearly' => '11000.00'];
        foreach ($creationOrder as $period) {
            $this->price($fee, $period, $amounts[$period]);
        }
        $student = $this->enrolledStudent();

        $batch = $this->makeBatch(fee: $fee, include: [$student->id]);
        app(MassBillingPreviewService::class)->preview($batch);
        $batch->refresh();

        $before = [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ];

        $run = app(MassBillingExecutionService::class)->execute($batch->fresh(), $this->accountant, '127.0.0.1', 'PHPUnit');

        $this->assertSame(0, $run->created_count);
        $this->assertSame(1, $run->skipped_count);
        $item = $run->items()->sole();
        $this->assertSame(BillingRunItem::STATUS_SKIPPED, $item->status);
        $this->assertNull($item->invoice_id);
        $this->assertNull($item->amount);
        $this->assertSame($before, [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ]);
    }
}
