<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\ServiceCoverage;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\TariffAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves tuition-transition:inventory reports every required fact and
 * diagnostic accurately against a deliberately messy fixture set (multiple
 * same-category Fees, a test-data Fee, a zero-reference orphan, a legacy
 * external Fee with real financial history, a mode-scoped FeePrice), and
 * that running it changes nothing — at the value level, not just row
 * counts — across every table it reads.
 */
class TuitionTransitionInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): array
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => '1-А', 'name_ar' => 'A', 'is_active' => true]);

        return [$year, $stage, $grade, $class];
    }

    public function test_command_succeeds_with_no_relevant_fees(): void
    {
        // A: an unrelated, non-tuition Fee exists, but no tuition/
        // tuition_regular/tuition_family/tuition_external row does.
        Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '100.00', 'is_active' => true]);

        $exitCode = Artisan::call('tuition-transition:inventory');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No relevant Fee rows exist', $output);
        $this->assertStringContainsString('No data was created, updated, or deleted.', $output);
    }

    public function test_command_reports_facts_and_diagnostics_and_performs_zero_writes(): void
    {
        [$year, , $grade, $class] = $this->structure();

        // E: an unrelated, non-tuition Fee must never appear in this
        // command's output at all.
        $foodFee = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '100.00', 'is_active' => true]);

        // J: two Fee rows in the SAME category ("tuition") — the command
        // must report both, never silently pick one as canonical.
        $primaryTuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        // K: a zero-reference, zero-FeePrice orphan candidate — reported
        // descriptively, never touched.
        $orphanTuition = Fee::create(['name_ru' => 'Обучение (дубликат)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);

        // F: a test-data tuition Fee.
        $testDataTuition = Fee::create(['name_ru' => 'Обучение (тест)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => false, 'is_test_data' => true]);

        // G: legacy tuition_external, WITH real financial history attached.
        $externalFee = Fee::create(['name_ru' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => '0.00', 'is_active' => true]);

        // H: no tuition_family Fee exists at all in this fixture set —
        // the command must say so explicitly, not stay silent.

        // I: a mode-scoped FeePrice on the primary tuition Fee, using the
        // canonical EnrollmentMode code as option_value.
        $modeScopedPrice = FeePrice::create([
            'fee_id' => $primaryTuition->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'payment_period' => 'yearly', 'amount' => '30000.00', 'start_date' => $year->start_date, 'end_date' => $year->end_date,
            'option_type' => 'enrollment_mode', 'option_value' => EnrollmentMode::EXTERNAL, 'is_active' => true,
        ]);
        // A generic (non-mode-scoped) price coexisting in a DIFFERENT scope
        // (different grade) on the same Fee — no overlap with the one above.
        FeePrice::create([
            'fee_id' => $primaryTuition->id, 'academic_year_id' => $year->id, 'grade_group' => '5–6 классы',
            'payment_period' => 'yearly', 'amount' => '35000.00', 'start_date' => $year->start_date, 'end_date' => $year->end_date,
            'is_active' => true,
        ]);
        // A FeePrice whose option_value equals an EnrollmentMode name_ru,
        // not a code — must NOT be reported as a canonical-code match.
        FeePrice::create([
            'fee_id' => $primaryTuition->id, 'academic_year_id' => $year->id, 'grade_group' => '7–8 классы',
            'payment_period' => 'yearly', 'amount' => '36000.00', 'start_date' => $year->start_date, 'end_date' => $year->end_date,
            'option_type' => 'enrollment_mode', 'option_value' => 'Семейная форма обучения', 'is_active' => true,
        ]);

        FeeBillingPeriod::create(['fee_id' => $primaryTuition->id, 'billing_period' => 'yearly']);

        // Real financial history on the legacy external Fee: an Invoice +
        // InvoiceItem + invoice_fee row + a ServiceCoverage + a
        // TariffAdjustment + a StudentServiceSubscription — the smallest
        // valid chain that populates every reference table the command
        // counts from, without pretending to be a realistic billing run.
        $student = Student::create(['name' => 'Иванов Иван']);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => null,
            'stage_id' => $grade->stage_id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'academic_year' => $year->name, 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01',
            'status' => 'active', 'is_active' => true,
        ]);
        $externalPrice = FeePrice::create([
            'fee_id' => $externalFee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'payment_period' => 'yearly', 'amount' => '25000.00', 'start_date' => $year->start_date, 'end_date' => $year->end_date,
            'is_active' => true,
        ]);
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'customer_name' => $student->name,
            'subtotal_amount' => '25000.00', 'total_amount' => '25000.00', 'paid_amount' => '0.00', 'remaining_amount' => '25000.00',
            'status' => Invoice::STATUS_UNPAID, 'currency' => 'EGP',
        ]);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $externalFee->id, 'description' => 'Экстернат', 'amount' => '25000.00',
        ]);
        DB::table('invoice_fee')->insert([
            'invoice_id' => $invoice->id, 'fee_id' => $externalFee->id, 'amount' => '25000.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subscription = StudentServiceSubscription::create([
            'enrollment_id' => $enrollment->id, 'fee_id' => $externalFee->id, 'status' => 'active',
        ]);
        $coverage = ServiceCoverage::create([
            'student_id' => $student->id, 'fee_id' => $externalFee->id, 'invoice_item_id' => $invoiceItem->id,
            'subscription_id' => $subscription->id, 'fee_price_id' => $externalPrice->id,
            'coverage_start' => '2026-09-01', 'coverage_end' => '2027-06-30', 'billing_unit' => 'monthly',
            'original_unit_price' => '25000.00',
        ]);
        TariffAdjustment::create([
            'student_id' => $student->id, 'fee_id' => $externalFee->id, 'service_coverage_id' => $coverage->id,
            'new_fee_price_id' => $externalPrice->id, 'kind' => 'debit', 'total_difference' => '1000.00', 'currency' => 'EGP',
        ]);
        FeeBillingPeriod::create(['fee_id' => $externalFee->id, 'billing_period' => 'yearly']);

        // ------------------------------------------------------------
        // L: value-level zero-write proof — full row snapshot, every
        // table the command reads that can hold relevant state.
        // ------------------------------------------------------------
        $before = $this->snapshotAllTables();

        $exitCode = Artisan::call('tuition-transition:inventory');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // B: relevant Fee rows reported.
        $this->assertStringContainsString((string) $primaryTuition->id, $output);
        $this->assertStringContainsString('Обучение', $output);
        $this->assertStringContainsString((string) $orphanTuition->id, $output);
        $this->assertStringContainsString((string) $testDataTuition->id, $output);
        $this->assertStringContainsString((string) $externalFee->id, $output);

        // E: the unrelated Food Fee never appears.
        $this->assertStringNotContainsString('Питание', $output);
        $this->assertStringNotContainsString((string) $foodFee->id.' '.'food', $output);

        // C: FeePrice dimensions reported accurately.
        $this->assertStringContainsString('30000.00', $output);
        $this->assertStringContainsString('enrollment_mode', $output);
        $this->assertStringContainsString('external', $output);

        // D: reference counts reported accurately for the Fee with history —
        // the full row is matched in one anchored regex (invoice_items=1,
        // invoices=0 [no direct invoices.fee_id was set], invoice_fee=1,
        // fee_prices=1, subscriptions=1, coverages=1, tariff_adjustments=1,
        // billing_periods=1) so a wrong column can't accidentally satisfy a
        // looser, unanchored pattern.
        $this->assertMatchesRegularExpression(
            '/'.preg_quote((string) $externalFee->id, '/').'\s*\|\s*tuition_external\s*\|\s*Экстернат\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|/u',
            $output
        );

        // F: test-data Fee flagged, not modified (modification proven by L below).
        $this->assertStringContainsString('TEST DATA', $output);
        $this->assertStringContainsString((string) $testDataTuition->id, $output);

        // G: legacy external reported.
        $this->assertStringContainsString('LEGACY EXTERNAL', $output);
        $this->assertStringContainsString((string) $externalFee->id, $output);

        // H: legacy family explicitly reported as absent, not silently skipped.
        $this->assertStringContainsString('LEGACY FAMILY — no Fee rows found', $output);

        // I: mode-scoped option_value reported exactly; canonical-code
        // matching is diagnostic only, and a name_ru-valued option_value
        // is explicitly NOT treated as a canonical-code match.
        $this->assertStringContainsString('option_value="external"', $output);
        $this->assertStringContainsString('matches a canonical EnrollmentMode code exactly', $output);
        $this->assertStringContainsString('option_value="Семейная форма обучения"', $output);
        $this->assertStringContainsString('does NOT match any canonical EnrollmentMode code', $output);

        // J: multiple same-category Fees reported, never auto-resolved.
        $this->assertStringContainsString('MULTIPLE ROWS SAME CATEGORY', $output);
        $this->assertStringContainsString((string) $primaryTuition->id, $output);
        $this->assertStringContainsString((string) $orphanTuition->id, $output);

        // K: the orphan is described, never deleted/deactivated.
        $this->assertStringContainsString('ZERO FEEPRICE ROWS', $output);
        $this->assertStringContainsString('ZERO OPERATIONAL REFERENCES', $output);

        // M: no failure/non-zero exit despite duplicates, a missing
        // category, a test-data row, and legacy rows all being present.
        $this->assertSame(0, $exitCode);

        // L: strict equality proves no in-place mutation anywhere, not
        // merely unchanged counts.
        $this->assertSame($before, $this->snapshotAllTables());
    }

    /**
     * Deterministically ordered, full-column snapshot of every table this
     * command reads that can contain relevant state. Strict equality
     * against this after running the command catches an in-place UPDATE
     * that a row-count comparison alone would miss.
     *
     * fee_billing_periods is the real table (FeeBillingPeriod model) — this
     * schema has no fee_billing_options table.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function snapshotAllTables(): array
    {
        $tables = [
            'fees', 'fee_prices', 'invoice_items', 'invoices', 'invoice_fee',
            'student_service_subscriptions', 'service_coverages', 'tariff_adjustments', 'fee_billing_periods',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->toArray();
        }

        return $snapshot;
    }
}
