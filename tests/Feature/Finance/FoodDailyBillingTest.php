<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\CalendarEvent;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\PaymentAllocationCoveragePeriod;
use App\Models\ServiceCoverage;
use App\Services\Finance\FoodBillableDayCalculator;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\TariffAdjustmentService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Food flexible-duration corrective pass. Food is priced daily and can be
 * purchased for a single day, one school week, N teaching days (counted
 * forward from a start date), one calendar month, several months, or any
 * custom bounded range — never a simple calendar-day count, never
 * attendance-driven, and never forced through CalendarPeriodCalculator's
 * month/quarter grouping (built for Tuition/Transport's billing_period
 * concept, structurally incompatible with a non-month-aligned Food
 * purchase). Food always settles as its own dedicated lump-sum
 * installment/ServiceCoverage/InstallmentCoveragePeriod, independent of
 * however many calendar months its resolved range happens to span.
 */
class FoodDailyBillingTest extends FinanceOperationsTestCase
{
    private AcademicCalendar $calendar;
    private MealPlan $mealPlan;
    private Fee $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = AcademicCalendar::create([
            'academic_year_id' => $this->year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);
        $this->mealPlan = MealPlan::create([
            'name_ru' => 'Полный рацион', 'meal_type' => 'both', 'period' => 'daily',
            'price' => '100.00', 'is_active' => true,
        ]);
        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
    }

    private function price(string $amount = '100.00', string $start = '2026-08-01', string $end = '2027-06-30'): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id,
            'amount' => $amount, 'currency' => 'EGP', 'start_date' => $start, 'end_date' => $end, 'is_active' => true,
        ]);
    }

    /** custom_range is the default mode used by most tests — it maps directly onto an explicit [start,end]. */
    private function issue(string $start = '2026-09-01', string $end = '2026-09-30', ?string $key = null, array $itemOverrides = []): Invoice
    {
        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => $end, 'pricing_date' => $start,
            'items' => [array_merge([
                'fee_id' => $this->food->id, 'quantity' => 1,
                'payment_period' => 'daily', 'option_type' => 'meal_plan',
                'option_value' => (string) $this->mealPlan->id,
                'food_duration_mode' => 'custom_range',
                'food_range_start' => $start, 'food_range_end' => $end,
            ], $itemOverrides)],
            'payment_type' => 'calendar',
        ], $this->accountant, idempotencyKey: $key);
    }

    private function quickPayload(array $foodOverrides = [], string $paid = '0.00', ?string $token = null): array
    {
        return [
            'student_last_name_ru' => 'Петрова', 'student_first_name_ru' => 'Анна',
            'phone' => '+201001234567', 'registration_date' => '2026-09-01',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->enrollment->stage_id,
            'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
            'payment_type' => 'calendar',
            'payment_method' => 'cash', 'idempotency_token' => $token ?? (string) Str::uuid(),
            'services' => [array_merge([
                'fee_id' => $this->food->id, 'quantity' => 1, 'paid_now' => $paid,
                'meal_plan_id' => $this->mealPlan->id,
                'food_duration_mode' => 'month',
                'food_month' => '2026-09', 'food_end_month' => '2026-12',
            ], $foodOverrides)],
        ];
    }

    public function test_canonical_calendar_excludes_weekends_holidays_and_closures_but_honours_teaching_override(): void
    {
        CalendarEvent::create(['academic_calendar_id' => $this->calendar->id, 'name' => 'Holiday', 'start_date' => '2026-09-06', 'end_date' => '2026-09-06', 'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY, 'is_active' => true]);
        CalendarEvent::create(['academic_calendar_id' => $this->calendar->id, 'name' => 'Closure', 'start_date' => '2026-09-07', 'end_date' => '2026-09-07', 'type' => CalendarEvent::TYPE_SCHOOL_EVENT, 'effect' => CalendarEvent::EFFECT_NON_TEACHING, 'is_active' => true]);
        CalendarEvent::create(['academic_calendar_id' => $this->calendar->id, 'name' => 'Saturday school', 'start_date' => '2026-09-05', 'end_date' => '2026-09-05', 'type' => CalendarEvent::TYPE_TEACHING_OVERRIDE, 'effect' => CalendarEvent::EFFECT_TEACHING_DAY, 'is_active' => true]);

        $result = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-01', '2026-09-07');

        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-05'], $result['billable_dates']);
        $this->assertSame(4, $result['billable_day_count']);
        $this->assertSame(3, $result['excluded_day_count']);
    }

    public function test_one_day_purchase_creates_a_single_lump_sum_installment(): void
    {
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'day', 'food_date' => '2026-09-01']);

        $this->assertSame('100.00', $invoice->total_amount);
        $this->assertCount(1, $invoice->installments);
        $this->assertSame('100.00', $invoice->installments()->sole()->amount);
        $period = InstallmentCoveragePeriod::sole();
        $this->assertSame('2026-09-01', $period->period_start->toDateString());
        $this->assertSame('2026-09-01', $period->period_end->toDateString());
    }

    public function test_one_day_purchase_on_a_non_teaching_day_fails_closed(): void
    {
        $this->price();
        // 2026-09-04 is a Friday — a non-working day per weekly_days_off.
        $this->expectException(ValidationException::class);
        $this->issue(itemOverrides: ['food_duration_mode' => 'day', 'food_date' => '2026-09-04']);
    }

    public function test_one_school_week_purchase_excludes_a_mid_week_holiday(): void
    {
        CalendarEvent::create(['academic_calendar_id' => $this->calendar->id, 'name' => 'Holiday', 'start_date' => '2026-09-02', 'end_date' => '2026-09-02', 'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY, 'is_active' => true]);
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'school_week', 'food_week_start' => '2026-08-30']);

        // Sun 08-30..Sat 09-05 (7-day school-week span) minus Wed 09-02
        // (holiday) minus Fri/Sat (weekly days off) = 4 billable days.
        $this->assertSame('400.00', $invoice->total_amount);
        $item = $invoice->items()->sole();
        $this->assertSame(4, $item->metadata['food_billable_day_count']);
    }

    public function test_n_teaching_days_purchase_skips_weekend_and_holiday_and_stops_at_exactly_n(): void
    {
        CalendarEvent::create(['academic_calendar_id' => $this->calendar->id, 'name' => 'Holiday', 'start_date' => '2026-09-08', 'end_date' => '2026-09-08', 'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY, 'is_active' => true]);
        $this->price();
        // From Thu 2026-09-03: Thu(1) — Fri/Sat off — Sun(2) Mon(3) Tue 09-08
        // is a holiday(skip) Wed(4) Thu(5) — Fri/Sat off — Sun 09-13(6).
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-03', 'food_day_count' => 6]);

        $item = $invoice->items()->sole();
        $this->assertSame(6, $item->metadata['food_billable_day_count']);
        $this->assertSame('600.00', $invoice->total_amount);
        $period = InstallmentCoveragePeriod::sole();
        $this->assertSame('2026-09-03', $period->period_start->toDateString());
        $this->assertSame('2026-09-13', $period->period_end->toDateString());
    }

    public function test_n_teaching_days_count_exhausting_the_academic_year_fails_closed(): void
    {
        // resolveForwardFromCount() must never silently return short of the
        // requested count — starting a few days before the academic year's
        // own end (2027-06-30) and asking for far more teaching days than
        // remain must fail closed, not truncate the range.
        $this->expectException(ValidationException::class);
        app(FoodBillableDayCalculator::class)->resolveForwardFromCount($this->year, '2027-06-25', 100);
    }

    public function test_n_teaching_days_crossing_a_tariff_change_produces_an_exact_segmented_amount(): void
    {
        $this->price('170.00', '2026-08-01', '2026-09-08');
        $this->price('190.00', '2026-09-09', '2027-06-30');
        // 2026-09-01 (Tue) .. 10 teaching days forward: Tue-Thu(3) + Sun-Thu(5) + Sun-Mon(2) = 10, ending 2026-09-14.
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 10]);

        $item = $invoice->items()->sole();
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(2, $segments);
        $this->assertSame(6, $segments[0]['billable_day_count']);
        $this->assertSame(4, $segments[1]['billable_day_count']);
        $expected = bcadd(bcmul('6', '170.00', 2), bcmul('4', '190.00', 2), 2);
        $this->assertSame($expected, $item->amount);
        $this->assertSame($expected, $invoice->installments()->sole()->amount);
    }

    public function test_custom_multi_month_range_not_aligned_to_month_boundaries(): void
    {
        $this->price();
        $invoice = $this->issue('2026-09-15', '2026-11-20', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-15', 'food_range_end' => '2026-11-20',
        ]);

        $expectedDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-15', '2026-11-20')['billable_day_count'];
        $this->assertSame(bcmul((string) $expectedDays, '100.00', 2), $invoice->total_amount);
        // A single lump-sum installment regardless of spanning 3 calendar months.
        $this->assertCount(1, $invoice->installments);
        $period = InstallmentCoveragePeriod::sole();
        $this->assertSame('2026-09-15', $period->period_start->toDateString());
        $this->assertSame('2026-11-20', $period->period_end->toDateString());
    }

    public function test_one_month_uses_billable_school_days_and_reconciles_graph(): void
    {
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09']);
        $expectedDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-01', '2026-09-30')['billable_day_count'];
        $expected = bcmul((string) $expectedDays, '100.00', 2);

        $this->assertSame($expected, $invoice->total_amount);
        $this->assertSame($expected, $invoice->items()->sole()->amount);
        $this->assertSame($expected, $invoice->installments()->sole()->amount);
        $this->assertSame($expected, InstallmentCoveragePeriod::sole()->amount);
        $this->assertSame('daily', ServiceCoverage::sole()->billing_unit);
    }

    public function test_four_month_range_stops_in_december_and_is_a_single_lump_sum_installment(): void
    {
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12']);

        // Food never auto-splits into monthly installments just because
        // the resolved range spans several calendar months — payment
        // schedule stays a separate concept from service coverage.
        $this->assertCount(1, $invoice->installments);
        $this->assertCount(1, InstallmentCoveragePeriod::all());
        $coverage = ServiceCoverage::sole();
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame('2026-12-31', $coverage->coverage_end->toDateString());
        $this->assertSame($invoice->total_amount, $invoice->installments()->sole()->amount);
    }

    public function test_mid_month_tariff_change_prices_each_billable_date_and_records_segments(): void
    {
        $this->price('100.00', '2026-08-01', '2026-09-15');
        $this->price('110.00', '2026-09-16', '2027-06-30');
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09']);
        $item = $invoice->items()->sole();

        $firstDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-01', '2026-09-15')['billable_day_count'];
        $secondDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-16', '2026-09-30')['billable_day_count'];
        $expected = bcadd(bcmul((string) $firstDays, '100.00', 2), bcmul((string) $secondDays, '110.00', 2), 2);
        $this->assertSame($expected, $item->amount);
        $this->assertCount(2, $item->metadata['food_tariff_segments']);
    }

    public function test_tariff_change_exactly_on_first_billable_day_uses_only_the_new_tariff(): void
    {
        // Boundary case: the new tariff's own start_date equals the very
        // first billable day of the coverage range. Every billable day
        // must use the new tariff — no stale/expired old-tariff segment.
        $this->price('100.00', '2026-08-01', '2026-08-31');
        $new = $this->price('150.00', '2026-09-01', '2027-06-30');
        // 2026-09-01 (Tue) .. 2026-09-03 (Thu): 3 consecutive teaching days.
        $invoice = $this->issue('2026-09-01', '2026-09-03', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-01', 'food_range_end' => '2026-09-03',
        ]);

        $item = $invoice->items()->sole();
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(1, $segments);
        $this->assertSame($new->id, $segments[0]['fee_price_id']);
        $this->assertSame('2026-09-01', $segments[0]['start']);
        $this->assertSame('2026-09-03', $segments[0]['end']);
        $this->assertSame(3, $segments[0]['billable_day_count']);
        $this->assertSame('450.00', $item->amount);
        $this->assertSame('450.00', $invoice->total_amount);
    }

    public function test_tariff_change_exactly_on_last_billable_day_splits_only_the_final_day(): void
    {
        // Boundary case: the old tariff's own end_date is the day BEFORE
        // the range's last billable day, and the new tariff's start_date
        // is exactly the final billable day — only that last day must
        // price at the new rate.
        $old = $this->price('100.00', '2026-08-01', '2026-09-02');
        $new = $this->price('150.00', '2026-09-03', '2027-06-30');
        $invoice = $this->issue('2026-09-01', '2026-09-03', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-01', 'food_range_end' => '2026-09-03',
        ]);

        $item = $invoice->items()->sole();
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(2, $segments);
        $this->assertSame($old->id, $segments[0]['fee_price_id']);
        $this->assertSame('2026-09-01', $segments[0]['start']);
        $this->assertSame('2026-09-02', $segments[0]['end']);
        $this->assertSame(2, $segments[0]['billable_day_count']);
        $this->assertSame('200.00', $segments[0]['amount']);
        $this->assertSame($new->id, $segments[1]['fee_price_id']);
        $this->assertSame('2026-09-03', $segments[1]['start']);
        $this->assertSame('2026-09-03', $segments[1]['end']);
        $this->assertSame(1, $segments[1]['billable_day_count']);
        $this->assertSame('150.00', $segments[1]['amount']);
        $this->assertSame('350.00', $item->amount);
        $this->assertSame('350.00', $invoice->total_amount);
    }

    public function test_tariff_change_landing_on_a_non_teaching_day_takes_effect_on_the_next_teaching_day(): void
    {
        // Boundary case: the new tariff's effective_from date (2026-09-04,
        // a Friday day-off) is itself never billable — the change must
        // still take effect starting the NEXT actual teaching day
        // (2026-09-06), and the non-teaching effective date generates no
        // charge at all under either tariff.
        $old = $this->price('100.00', '2026-08-01', '2026-09-03');
        $new = $this->price('150.00', '2026-09-04', '2027-06-30');
        // 2026-09-01 (Tue) .. 2026-09-08 (Tue): billable = 01,02,03,06,07,08
        // (04 Fri, 05 Sat excluded by weekly_days_off).
        $invoice = $this->issue('2026-09-01', '2026-09-08', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-01', 'food_range_end' => '2026-09-08',
        ]);

        $item = $invoice->items()->sole();
        $this->assertSame(6, $item->metadata['food_billable_day_count']);
        $this->assertSame(2, $item->metadata['food_excluded_day_count']);
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(2, $segments);
        $this->assertSame($old->id, $segments[0]['fee_price_id']);
        $this->assertSame('2026-09-01', $segments[0]['start']);
        $this->assertSame('2026-09-03', $segments[0]['end']);
        $this->assertSame(3, $segments[0]['billable_day_count']);
        $this->assertSame('300.00', $segments[0]['amount']);
        $this->assertSame($new->id, $segments[1]['fee_price_id']);
        $this->assertSame('2026-09-06', $segments[1]['start']);
        $this->assertSame('2026-09-08', $segments[1]['end']);
        $this->assertSame(3, $segments[1]['billable_day_count']);
        $this->assertSame('450.00', $segments[1]['amount']);
        $this->assertSame('750.00', $item->amount);
        $this->assertSame('750.00', $invoice->total_amount);
    }

    /**
     * Business policy, authoritative: FUTURE SERVICE PREPAYMENT = YES,
     * FUTURE TARIFF BACKDATING = NO. Food pricing is keyed purely on the
     * SERVICE date vs. each tariff's own effective window — never on the
     * purchase/request date (confirmed by inspection: priceFoodDailyLine()
     * never reads $pricingDate at all, only $foodResolution['billable_dates']).
     * A tariff that is itself "in the future" relative to today is a
     * perfectly valid, un-exceptional match for a service date that falls
     * inside its own window; what must never happen is a tariff being
     * applied to a service day BEFORE its own effective_from, no matter how
     * "purchase happens ahead of time" that day's request otherwise is.
     */
    public function test_future_prepayment_case_a_a_sole_tariff_effective_for_the_requested_future_service_date_is_allowed(): void
    {
        $tariff = $this->price('190.00', '2026-10-01', '2027-06-30');
        $invoice = $this->issue('2026-10-01', '2026-10-10', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-10-01', 'food_range_end' => '2026-10-10',
        ]);

        $expectedDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-10-01', '2026-10-10')['billable_day_count'];
        $item = $invoice->items()->sole();
        $this->assertSame(bcmul((string) $expectedDays, '190.00', 2), $invoice->total_amount);
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(1, $segments);
        $this->assertSame($tariff->id, $segments[0]['fee_price_id']);
        $this->assertSame($expectedDays, $segments[0]['billable_day_count']);
    }

    public function test_future_prepayment_case_b_a_range_crossing_a_scheduled_future_tariff_change_is_segmented_exactly(): void
    {
        $old = $this->price('170.00', '2026-08-01', '2026-09-30');
        $new = $this->price('190.00', '2026-10-01', '2027-06-30');
        $invoice = $this->issue('2026-09-20', '2026-10-10', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-20', 'food_range_end' => '2026-10-10',
        ]);

        $septDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-20', '2026-09-30')['billable_day_count'];
        $octDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-10-01', '2026-10-10')['billable_day_count'];
        $expected = bcadd(bcmul((string) $septDays, '170.00', 2), bcmul((string) $octDays, '190.00', 2), 2);

        $item = $invoice->items()->sole();
        $this->assertSame($expected, $item->amount);
        $this->assertSame($expected, $invoice->total_amount);
        $segments = $item->metadata['food_tariff_segments'];
        $this->assertCount(2, $segments);
        $this->assertSame($old->id, $segments[0]['fee_price_id']);
        $this->assertSame($septDays, $segments[0]['billable_day_count']);
        $this->assertSame($new->id, $segments[1]['fee_price_id']);
        $this->assertSame($octDays, $segments[1]['billable_day_count']);
    }

    public function test_future_prepayment_case_c_a_lone_future_tariff_must_never_be_backdated_onto_earlier_service_days(): void
    {
        // Only an October tariff exists. September service days inside the
        // same requested range must NOT silently borrow it — even though
        // it is the sole candidate for this dimension, which is exactly
        // the "sole candidate usable before its own start_date" prepayment
        // exemption every OTHER Fee category gets. priceFoodDailyLine()'s
        // own stricter per-date window recheck must reject this.
        $this->price('190.00', '2026-10-01', '2027-06-30');

        $this->expectException(ValidationException::class);
        $this->issue('2026-09-20', '2026-10-10', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-20', 'food_range_end' => '2026-10-10',
        ]);
    }

    public function test_future_prepayment_case_d_a_future_range_with_no_covering_tariff_at_all_fails_closed(): void
    {
        // No Food tariff exists at all yet — must fail closed, never guess
        // or carry forward an unrelated tariff.
        $this->expectException(ValidationException::class);
        $this->issue('2026-10-01', '2026-10-31', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-10-01', 'food_range_end' => '2026-10-31',
        ]);
    }

    public function test_full_prepayment_is_one_payment_explicitly_mapped_to_the_single_period(): void
    {
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12']);
        $item = $invoice->items()->sole();
        $periods = InstallmentCoveragePeriod::orderBy('period_start')->get();
        $mappings = $periods->map(fn ($period) => ['invoice_item_id' => $item->id, 'installment_coverage_period_id' => $period->id, 'amount' => $period->amount])->all();

        app(InvoicePaymentService::class)->record($invoice->id, $this->cash->id, $invoice->total_amount, 'cash', (string) Str::uuid(), $this->accountant, coveragePeriodAllocations: $mappings);

        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, PaymentAllocationCoveragePeriod::count());
        $periods->each->refresh();
        $this->assertTrue($periods->every(fn ($period) => $period->isSettled()));
        $this->assertTrue($invoice->installments()->get()->every(fn ($installment) => $installment->remaining_amount === '0.00'));
    }

    public function test_lump_sum_payment_for_a_multi_month_custom_range_settles_in_one_payment(): void
    {
        $this->price();
        $invoice = $this->issue('2026-09-10', '2026-12-18', itemOverrides: [
            'food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-10', 'food_range_end' => '2026-12-18',
        ]);

        $this->assertCount(1, $invoice->installments);
        $installment = $invoice->installments()->sole();
        app(InvoicePaymentService::class)->record($invoice->id, $this->cash->id, $invoice->total_amount, 'cash', (string) Str::uuid(), $this->accountant, installmentId: $installment->id);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', $installment->fresh()->remaining_amount);
    }

    public function test_null_monthly_and_ambiguous_daily_tariffs_fail_closed_without_graph(): void
    {
        FeePrice::create(['fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'payment_period' => null, 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id, 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        try { $this->issue(); $this->fail('Expected missing daily tariff rejection.'); } catch (ValidationException) {}
        $this->assertSame(0, Invoice::count());

        $this->price();
        $this->price('101.00');
        try { $this->issue(); $this->fail('Expected ambiguous tariff rejection.'); } catch (ValidationException) {}
        $this->assertSame(0, Invoice::count());
    }

    public function test_zero_billable_range_and_unsupported_food_strategy_fail_closed(): void
    {
        $this->calendar->update(['weekly_days_off' => ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']]);
        $this->price();
        try { $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09']); $this->fail('Expected zero-day rejection.'); } catch (ValidationException) {}
        $this->assertSame(0, Invoice::count());

        $this->calendar->update(['weekly_days_off' => ['fri', 'sat']]);
        // Food structurally has no quarterly/yearly "collection cadence"
        // concept at all — it is never routed through billing_period/
        // CalendarPeriodCalculator, so an unsupported strategy can now
        // only be expressed as an invalid/missing food_duration_mode.
        // Passing a stray billing_period alongside a valid mode is simply
        // ignored for a Food-only invoice (proven separately).
        foreach ([null, 'quarterly', 'yearly'] as $mode) {
            try {
                app(InvoiceIssuanceService::class)->issue($this->student, [
                    'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                    'due_date' => '2026-12-31', 'pricing_date' => '2026-09-01',
                    'items' => [['fee_id' => $this->food->id, 'quantity' => 1, 'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id, 'food_duration_mode' => $mode, 'food_month' => '2026-09', 'food_end_month' => '2026-12']],
                    'payment_type' => 'calendar',
                ], $this->accountant);
                $this->fail("Expected unsupported Food duration mode '{$mode}' to be rejected.");
            } catch (ValidationException) {}
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_food_ignores_a_stray_billing_period_when_it_is_the_only_invoice_item(): void
    {
        $this->price();
        // billing_period is meaningless for a Food-only invoice (Food never
        // consults it) — an accidental/leftover value must never block or
        // change the resolved purchase.
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $this->food->id, 'quantity' => 1, 'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id, 'food_duration_mode' => 'month', 'food_month' => '2026-09']],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        $this->assertCount(1, $invoice->installments);
    }

    public function test_client_submitted_quantity_conflicting_with_billable_day_count_is_rejected(): void
    {
        $this->price();
        $this->expectException(ValidationException::class);
        app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-30', 'pricing_date' => '2026-09-01',
            'items' => [[
                'fee_id' => $this->food->id, 'quantity' => 999, 'payment_period' => 'daily',
                'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id,
                'food_duration_mode' => 'month', 'food_month' => '2026-09',
            ]],
            'payment_type' => 'calendar',
        ], $this->accountant);
    }

    public function test_food_adjustment_counts_only_teaching_days(): void
    {
        $old = $this->price('100.00');
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09']);
        $coverage = ServiceCoverage::sole();
        $old->update(['end_date' => '2026-09-15']);
        $new = $this->price('110.00', '2026-09-16', '2027-06-30');
        $expectedDays = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-16', '2026-09-30')['billable_day_count'];

        $preview = app(TariffAdjustmentService::class)->preview($coverage, $new);

        $this->assertSame($expectedDays, $preview['units']);
        $this->assertSame(bcmul((string) $expectedDays, '10.00', 2), $preview['total_difference']);
        $this->assertSame($old->id, $preview['previous_fee_price']->id);
        $this->assertSame($invoice->id, $coverage->invoiceItem->invoice_id);
    }

    public function test_tariff_adjustment_uses_the_same_resolver_regardless_of_the_original_purchase_mode(): void
    {
        $old = $this->price('170.00', '2026-08-01', '2027-06-30');
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 15]);
        $coverage = ServiceCoverage::sole();
        $old->update(['end_date' => '2026-09-10']);
        $new = $this->price('190.00', '2026-09-11', '2027-06-30');

        $preview = app(TariffAdjustmentService::class)->preview($coverage, $new);

        // The adjustment resolver counts teaching days over the coverage's
        // OWN stored [coverage_start, coverage_end] — whatever duration
        // mode originally produced them — never diffInDays().
        $expectedUnits = app(FoodBillableDayCalculator::class)->calculate(
            $this->year,
            max($coverage->coverage_start->toDateString(), $new->start_date),
            $coverage->coverage_end->toDateString(),
        )['billable_day_count'];
        $this->assertSame($expectedUnits, $preview['units']);
        $this->assertSame($invoice->id, $coverage->invoiceItem->invoice_id);
    }

    public function test_issuance_idempotency_binds_food_range_and_meal_plan(): void
    {
        $this->price();
        $key = (string) Str::uuid();
        $first = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12'], key: $key);
        $this->assertSame($first->id, $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12'], key: $key)->id);
        try {
            $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2027-01'], key: $key);
            $this->fail('Expected changed range conflict.');
        } catch (ValidationException) {}
        $this->assertSame(1, Invoice::count());
    }

    public function test_issuance_idempotency_binds_the_raw_teaching_day_count(): void
    {
        $this->price();
        $key = (string) Str::uuid();
        $this->issue(itemOverrides: ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 10], key: $key);
        // Same start date, a DIFFERENT count — must never replay as the
        // same purchase, even though a since-changed calendar could in
        // principle make two different counts resolve to nearby dates.
        try {
            $this->issue(itemOverrides: ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 11], key: $key);
            $this->fail('Expected changed day-count conflict.');
        } catch (ValidationException) {}
        $this->assertSame(1, Invoice::count());
    }

    public function test_replay_after_calendar_change_preserves_original_historical_meaning(): void
    {
        // Final independent review, MEDIUM finding: replayInvoice() is
        // reached BEFORE FoodBillableDayCalculator ever runs again (the
        // idempotency-key lookup in issue() short-circuits ahead of the
        // food_resolution call) — this proves that design holds end-to-end:
        // a calendar edit made AFTER the original issuance must never alter
        // what a same-key retry returns.
        $this->price(); // 100.00/day
        $key = (string) Str::uuid();
        // 2026-09-01 (Tue) .. 2026-09-10 (Thu), weekly days off fri/sat only:
        // billable = 01,02,03,06,07,08,09,10 = 8 teaching days -> 800.00.
        $first = $this->issue('2026-09-01', '2026-09-10', key: $key);

        $this->assertSame('800.00', $first->total_amount);
        $originalItem = $first->items()->sole();
        $originalItemMetadata = $originalItem->metadata;
        $this->assertSame(8, $originalItemMetadata['food_billable_day_count']);
        $originalCoverage = ServiceCoverage::sole();
        $originalCoverageMetadata = $originalCoverage->metadata;
        $originalCoverageStart = $originalCoverage->coverage_start->toDateString();
        $originalCoverageEnd = $originalCoverage->coverage_end->toDateString();
        $originalPeriod = InstallmentCoveragePeriod::sole();

        // A holiday added AFTER issuance, landing on 2026-09-07 — one of
        // the 8 days already billed above. Recalculating the same range
        // today would yield only 7 billable days (700.00).
        CalendarEvent::create([
            'academic_calendar_id' => $this->calendar->id, 'name' => 'Внеплановый выходной',
            'start_date' => '2026-09-07', 'end_date' => '2026-09-07',
            'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY, 'is_active' => true,
        ]);
        $this->assertSame(7, app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-01', '2026-09-10')['billable_day_count']);

        $replayed = $this->issue('2026-09-01', '2026-09-10', key: $key);

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, ServiceCoverage::count());
        $this->assertSame(1, InstallmentCoveragePeriod::count());

        $replayed->refresh();
        $this->assertSame('800.00', $replayed->total_amount);
        $replayedItem = $replayed->items()->sole()->fresh();
        $this->assertSame($originalItemMetadata, $replayedItem->metadata);
        $this->assertSame(8, $replayedItem->metadata['food_billable_day_count']);

        $originalCoverage->refresh();
        $this->assertSame($originalCoverageStart, $originalCoverage->coverage_start->toDateString());
        $this->assertSame($originalCoverageEnd, $originalCoverage->coverage_end->toDateString());
        $this->assertSame($originalCoverageMetadata, $originalCoverage->metadata);

        $originalPeriod->refresh();
        $this->assertSame('800.00', $originalPeriod->amount);
    }

    public function test_quick_registration_full_prepayment_creates_one_payment_and_single_period_graph(): void
    {
        $this->price();
        $days = app(FoodBillableDayCalculator::class)->calculate($this->year, '2026-09-01', '2026-12-31')['billable_day_count'];
        $total = bcmul((string) $days, '100.00', 2);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload(paid: $total));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->assertSame($total, $invoice->total_amount);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(1, $invoice->installments()->count());
        $this->assertSame(1, PaymentAllocationCoveragePeriod::count());
        $this->assertSame(1, InstallmentCoveragePeriod::count());
    }

    public function test_quick_registration_teaching_days_purchase(): void
    {
        $this->price();
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload([
            'food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 10,
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->assertSame('1000.00', $invoice->total_amount);
        $this->assertSame(10, $invoice->items()->sole()->metadata['food_billable_day_count']);

        // Final independent review, HIGH finding: QuickStudentRegistrationService's
        // post-issuance metadata merge (InvoiceItem::FINANCE_METADATA_KEYS allow-list)
        // must preserve the Food duration audit trail, not just the generic
        // coverage_start/coverage_end fields — re-fetch the PERSISTED row (not the
        // in-memory item still held by the request) to prove the merge survived
        // the round trip through the database, not just an in-memory computation.
        $resolution = app(FoodBillableDayCalculator::class)->resolveFromDurationSelection($this->year, [
            'food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 10,
        ]);
        $persistedMetadata = $invoice->items()->sole()->fresh()->metadata;
        $this->assertSame('teaching_days', $persistedMetadata['food_duration_mode']);
        $this->assertSame(10, $persistedMetadata['food_requested_day_count']);
        $this->assertSame($resolution['coverage_start'], $persistedMetadata['food_coverage_start']);
        $this->assertSame($resolution['coverage_end'], $persistedMetadata['food_coverage_end']);
    }

    public function test_food_partial_payment_is_one_explicit_period_allocation(): void
    {
        $this->price();
        $this->actingAs($this->accountant)->post(
            route('dashboard.quick-registration.store'),
            $this->quickPayload(paid: '150.00'),
        )->assertSessionHasNoErrors();

        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, PaymentAllocationCoveragePeriod::count());
        $period = InstallmentCoveragePeriod::sole();
        $this->assertSame('150.00', $period->netSettledAmount());
        $this->assertSame('partial', $period->settlementStatus());
    }

    public function test_full_food_prepayment_refund_reopens_the_period(): void
    {
        $this->price();
        $invoice = $this->issue(itemOverrides: ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12']);
        $item = $invoice->items()->sole();
        $periods = InstallmentCoveragePeriod::orderBy('period_start')->get();
        $mappings = $periods->map(fn ($period) => ['invoice_item_id' => $item->id, 'installment_coverage_period_id' => $period->id, 'amount' => $period->amount])->all();
        $payment = app(InvoicePaymentService::class)->record($invoice->id, $this->cash->id, $invoice->total_amount, 'cash', (string) Str::uuid(), $this->accountant, coveragePeriodAllocations: $mappings);

        app(InvoiceRefundService::class)->refund($payment->id, $payment->amount, 'Полный возврат питания', (string) Str::uuid(), $this->accountant, $this->cash->id);

        $periods->each->refresh();
        $this->assertTrue($periods->every(fn ($period) => $period->settlementStatus() === 'unpaid'));
        $this->assertTrue($invoice->installments()->get()->every(fn ($installment) => $installment->remaining_amount === $installment->amount));
    }

    public function test_food_and_tuition_bundled_get_independent_settlement_structures(): void
    {
        $this->price();
        $tuition = Fee::create(['name_ru' => 'Обучение помесячно', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2026-12-31', 'pricing_date' => '2026-09-01',
            // Bounds ONLY Tuition's own shared calendar schedule (still a
            // general, non-Food-specific InvoiceIssuanceService::issue()
            // capability) — Food resolves its own range independently from
            // its own food_month/food_end_month fields, never from this.
            'coverage_start' => '2026-09-01', 'coverage_end' => '2026-12-31',
            'items' => [
                ['fee_id' => $this->food->id, 'quantity' => 1, 'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id, 'food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-12'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'grade_id' => $this->enrollment->grade_id, 'payment_period' => 'monthly'],
            ],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        // Tuition still gets its own 4-month shared schedule; Food gets
        // exactly ONE extra lump-sum installment appended — the two
        // settlement structures never collapse into one shared schedule.
        $this->assertCount(5, $invoice->installments);
        $this->assertCount(2, $invoice->items);
        $this->assertCount(5, InstallmentCoveragePeriod::all());
        $foodCoverage = ServiceCoverage::where('fee_id', $this->food->id)->sole();
        $this->assertSame(1, InstallmentCoveragePeriod::where('service_coverage_id', $foodCoverage->id)->count());
        $this->assertSame($invoice->total_amount, bcadd((string) $invoice->installments()->sum('amount'), '0', 2));
    }

    public function test_quick_registration_rejects_unsupported_strategy_and_missing_daily_tariff_atomically(): void
    {
        $this->price();
        $payload = $this->quickPayload();
        $payload['billing_period'] = 'yearly';
        unset($payload['services'][0]['food_duration_mode'], $payload['services'][0]['food_month'], $payload['services'][0]['food_end_month']);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasErrors('services.0.food_duration_mode');
        $this->assertSame(1, \App\Models\Student::count());

        FeePrice::query()->delete();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload())
            ->assertSessionHasErrors();
        $this->assertSame(1, \App\Models\Student::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ServiceCoverage::count());
    }

    public function test_quick_registration_idempotency_replays_same_food_meaning_and_rejects_changed_range(): void
    {
        $this->price();
        $token = (string) Str::uuid();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload(token: $token))->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload(token: $token))->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload(['food_end_month' => '2027-01'], token: $token))
            ->assertSessionHasErrors('idempotency_key');
        $otherPlan = MealPlan::create(['name_ru' => 'Другой рацион', 'meal_type' => 'lunch', 'period' => 'daily', 'price' => '90.00', 'is_active' => true]);
        $changedPlan = $this->quickPayload(token: $token);
        $changedPlan['services'][0]['meal_plan_id'] = $otherPlan->id;
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $changedPlan)
            ->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, Invoice::count());
    }

    public function test_quick_registration_idempotency_rejects_changed_teaching_day_count(): void
    {
        $this->price();
        $token = (string) Str::uuid();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload([
            'food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 10,
        ], token: $token))->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->quickPayload([
            'food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-01', 'food_day_count' => 12,
        ], token: $token))->assertSessionHasErrors();
        $this->assertSame(1, Invoice::count());
    }
}
