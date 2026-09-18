<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\Finance\BillingTargetResolver;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\MassBillingEligibilityService;
use App\Services\Finance\MassBillingExecutionService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finance P2 mitigation: cross-batch duplicate charging in Mass Billing
 * cannot be safely hard-blocked for non-Registration Fees today (see the
 * Finance discovery this exists for — student+fee+academic_year is
 * intentionally too broad to be an authoritative charge identity, since a
 * repeat/recurring charge for the same student/Fee/year is completely
 * legitimate and structurally indistinguishable from an accidental
 * duplicate). This adds a non-blocking, informational-only operator
 * warning instead: MassBillingEligibilityService::duplicateWarningStudentIds()
 * reports which of the batch's CURRENTLY resolved target students already
 * have an existing Invoice for the exact same Fee in the exact same
 * academic year, from any source — surfaced on the batch's show screen,
 * never consulted by classify()/execution, never blocking or skipping
 * anything.
 */
class MassBillingDuplicateWarningTest extends MassBillingTestCase
{
    private function issueInvoice(Student $student, Fee $fee, ?AcademicYear $year = null): Invoice
    {
        return app(InvoiceIssuanceService::class)->issue($student, [
            'student_id' => $student->id, 'academic_year_id' => ($year ?? $this->year)->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    /**
     * A raw, directly-seeded historical invoice — used only for the
     * cross-year case, where the other year is inactive and therefore
     * cannot be issued against through the real, authoritative
     * InvoiceIssuanceService gate. Mirrors the established
     * bareInvoice()/seedRegistrationInvoice() convention already used in
     * MassBillingExecutionTest for the identical purpose.
     */
    private function seedHistoricalInvoice(Student $student, Fee $fee, AcademicYear $year): void
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '500.00', 'total_amount' => '500.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2026-06-30', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2025');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru,
            'unit_price' => '500.00', 'quantity' => 1, 'amount' => '500.00', 'paid_amount' => '0.00', 'remaining_amount' => '500.00',
        ]);
    }

    private function warningStudentIds(BillingBatch $batch): Collection
    {
        $studentIds = app(BillingTargetResolver::class)->resolve($batch);

        return app(MassBillingEligibilityService::class)->duplicateWarningStudentIds(
            (int) $batch->academic_year_id, (int) $batch->fee_id, $studentIds
        );
    }

    private function booksFee(string $suffix = ''): Fee
    {
        return Fee::create(['name_ru' => "Учебники $suffix", 'category' => Fee::CATEGORY_BOOKS, 'amount' => '500.00', 'is_active' => true]);
    }

    // 1. Existing same Fee + same year + targeted student -> counted.
    public function test_existing_invoice_same_fee_same_year_counts(): void
    {
        $fee = $this->booksFee();
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $this->assertSame([$student->id], $this->warningStudentIds($batch)->all());
    }

    // 2. Same Fee + a DIFFERENT academic year -> not counted.
    public function test_existing_invoice_different_year_does_not_count(): void
    {
        $fee = $this->booksFee();
        $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $student = $this->enrolledStudent($this->classA);
        $this->seedHistoricalInvoice($student, $fee, $otherYear);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $this->assertCount(0, $this->warningStudentIds($batch));
    }

    // 3. A DIFFERENT Fee, same year -> not counted.
    public function test_existing_invoice_different_fee_does_not_count(): void
    {
        $fee = $this->booksFee();
        $otherFee = Fee::create(['name_ru' => 'Экскурсия', 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => '300.00', 'is_active' => true]);
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $otherFee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $this->assertCount(0, $this->warningStudentIds($batch));
    }

    // 4. Existing invoice belongs to a student OUTSIDE the resolved target
    // set (a different class, not targeted) -> not counted.
    public function test_existing_invoice_for_student_outside_targets_does_not_count(): void
    {
        $fee = $this->booksFee();
        $this->enrolledStudent($this->classA);
        $outside = $this->enrolledStudent($this->classB);
        $this->issueInvoice($outside, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $this->assertCount(0, $this->warningStudentIds($batch));
    }

    // 5. An INCLUDE-override student (not in the targeted class at all) is
    // counted when a matching invoice exists — proves the real
    // BillingTargetResolver path (not a naive class-only query) is used.
    public function test_included_override_student_is_counted(): void
    {
        $fee = $this->booksFee();
        $included = $this->enrolledStudent($this->classB);
        $this->issueInvoice($included, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id], include: [$included->id]);

        $this->assertSame([$included->id], $this->warningStudentIds($batch)->all());
    }

    // 6. An EXCLUDE-override student is never counted, even with a
    // matching invoice.
    public function test_excluded_target_student_is_not_counted(): void
    {
        $fee = $this->booksFee();
        $excluded = $this->enrolledStudent($this->classA);
        $this->issueInvoice($excluded, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id], exclude: [$excluded->id]);

        $this->assertCount(0, $this->warningStudentIds($batch));
    }

    // 7. Multiple existing invoices for the SAME student/Fee/year count the
    // student exactly ONCE, never once per invoice.
    public function test_multiple_existing_invoices_same_student_counted_once(): void
    {
        $fee = $this->booksFee();
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);
        $this->issueInvoice($student, $fee);
        $this->issueInvoice($student, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $ids = $this->warningStudentIds($batch);
        $this->assertSame(1, $ids->count());
        $this->assertSame([$student->id], $ids->all());
    }

    // 8. Multiple targeted students with matching invoices produce the
    // exact distinct-student count — untargeted/unmatched students never
    // inflate it.
    public function test_multiple_targeted_students_produce_exact_distinct_count(): void
    {
        $fee = $this->booksFee();
        $a = $this->enrolledStudent($this->classA, suffix: 'A');
        $b = $this->enrolledStudent($this->classA, suffix: 'B');
        $this->enrolledStudent($this->classA, suffix: 'C'); // targeted, no invoice
        $this->issueInvoice($a, $fee);
        $this->issueInvoice($b, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);
        $ids = $this->warningStudentIds($batch);

        $this->assertSame(2, $ids->count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids->all());
    }

    // 9. A non-Registration Fee with an active warning does NOT block
    // execution — the second charge is legitimately issued.
    public function test_non_registration_execution_remains_allowed_despite_warning(): void
    {
        $fee = $this->booksFee();
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);
        $this->assertSame(1, $this->warningStudentIds($batch)->count());

        app(MassBillingPreviewService::class)->preview($batch);
        $run = app(MassBillingExecutionService::class)->execute($batch->fresh(), $this->accountant, '127.0.0.1', 'PHPUnit');

        $this->assertSame(1, $run->created_count);
        $this->assertSame(0, $run->skipped_count);
        $this->assertSame(2, Invoice::where('student_id', $student->id)->count());
    }

    // 10. Registration's existing hard duplicate protection is unchanged —
    // the shared warning logic never weakens or replaces it.
    public function test_registration_still_hard_blocks_despite_shared_warning_logic(): void
    {
        $fee = Fee::create(['name_ru' => 'Регистрация', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);
        $this->assertSame(1, $this->warningStudentIds($batch)->count());

        app(MassBillingPreviewService::class)->preview($batch);
        $run = app(MassBillingExecutionService::class)->execute($batch->fresh(), $this->accountant, '127.0.0.1', 'PHPUnit');

        $this->assertSame(0, $run->created_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame('registration_duplicate', $run->items()->sole()->skip_reason);
        $this->assertSame(1, Invoice::where('student_id', $student->id)->count());
    }

    // 11. Computing the warning itself is purely read-only.
    public function test_computing_the_warning_causes_zero_financial_writes(): void
    {
        $fee = $this->booksFee();
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);

        $before = [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ];

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);
        $this->warningStudentIds($batch);
        $this->warningStudentIds($batch);

        $this->assertSame($before, [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ]);
    }

    // 12. No matching existing invoice -> no warning at all.
    public function test_no_existing_invoice_produces_no_warning(): void
    {
        $fee = $this->booksFee();
        $this->enrolledStudent($this->classA);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $this->assertCount(0, $this->warningStudentIds($batch));
    }

    // Query quality: one set-based query for any number of targeted
    // students, never N+1.
    public function test_duplicate_warning_query_is_set_based_not_n_plus_one(): void
    {
        $fee = $this->booksFee();
        $students = collect(range(1, 5))->map(fn ($i) => $this->enrolledStudent($this->classA, suffix: (string) $i));
        $students->each(fn (Student $s) => $this->issueInvoice($s, $fee));

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);
        $studentIds = app(BillingTargetResolver::class)->resolve($batch);

        // Measure only duplicateWarningStudentIds() itself — target
        // resolution's own (fixed, pre-existing, unrelated) query cost is
        // out of scope for this assertion.
        DB::enableQueryLog();
        $result = app(MassBillingEligibilityService::class)->duplicateWarningStudentIds(
            (int) $batch->academic_year_id, (int) $batch->fee_id, $studentIds
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, $result->count());
        $this->assertSame(1, $queryCount);
    }

    // HTTP wiring: the warning renders on the show screen when applicable
    // and is absent when not.
    public function test_warning_renders_on_show_screen_when_applicable(): void
    {
        $fee = $this->booksFee();
        $student = $this->enrolledStudent($this->classA);
        $this->issueInvoice($student, $fee);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.mass-billing.show', $batch));

        $response->assertOk()->assertSee(__('mass_billing.duplicate_warning', ['count' => 1]));
    }

    public function test_warning_does_not_render_on_show_screen_when_not_applicable(): void
    {
        $fee = $this->booksFee();
        $this->enrolledStudent($this->classA);

        $batch = $this->makeBatch(fee: $fee, classIds: [$this->classA->id]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.mass-billing.show', $batch));

        $response->assertOk()->assertDontSeeText('уже есть счёт по этой услуге');
    }
}
