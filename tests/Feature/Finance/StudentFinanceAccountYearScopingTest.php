<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\StudentFinanceSummaryService;
use App\Support\AcademicYearLock;
use Illuminate\Support\Str;

/**
 * Finance Workspace corrective PR #4 — Student Financial Account becomes
 * academic-year aware. StudentFinanceSummaryService::summarizeByYear()
 * partitions the exact same calculate() every other summary already uses,
 * anchored on Invoice.academic_year_id (the only Finance record that
 * carries the column directly) — summarize()/summarizeMany() themselves are
 * untouched, so every existing flat consumer keeps its exact prior
 * behaviour.
 */
class StudentFinanceAccountYearScopingTest extends FinanceOperationsTestCase
{
    private AcademicYear $previousYear;

    private Enrollment $previousEnrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousYear = AcademicYear::create([
            'name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false,
        ]);
        // The previous year's end_date is already in the past, so Enrollment
        // (which implements ResolvesAcademicYear, unlike Invoice/
        // InvoicePayment/InvoiceItem/PaymentRefund — none of which are
        // academic-year-locked at all) requires the same explicit,
        // auditable escape hatch any historical-year fixture uses.
        $this->previousEnrollment = AcademicYearLock::withoutLock(fn () => Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->previousYear->id,
            'enrollment_mode_id' => $this->enrollment->enrollment_mode_id, 'stage_id' => $this->enrollment->stage_id,
            'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'academic_year' => $this->previousYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
            'status' => 'active', 'is_active' => true,
        ]));
    }

    private function invoiceForYear(AcademicYear $year, string $total): Invoice
    {
        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $year->id, 'customer_name' => $this->student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => $total, 'total_amount' => $total, 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => $total, 'status' => Invoice::STATUS_UNPAID,
            'due_date' => $year->end_date, 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, $year->name);
        $invoice->save();
        InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение', 'unit_price' => $total, 'quantity' => 1, 'amount' => $total, 'paid_amount' => '0.00', 'remaining_amount' => $total]);

        return $invoice;
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: $amount,
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
    }

    // ----- 1/2/3/4. Two years of activity, correctly separated ------------

    public function test_selected_year_summary_shows_only_that_years_charged_paid_and_debt(): void
    {
        $previousInvoice = $this->invoiceForYear($this->previousYear, '100000.00');
        $this->pay($previousInvoice, '90000.00');

        $currentInvoice = $this->invoiceForYear($this->year, '50000.00');
        $this->pay($currentInvoice, '20000.00');

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Student::count(), 'the same Student, never duplicated across years');

        $byYear = app(StudentFinanceSummaryService::class)->summarizeByYear($this->student);

        $previous = $byYear['byYear'][$this->previousYear->id];
        $this->assertSame('100000.00', $previous['gross_invoiced']);
        $this->assertSame('90000.00', $previous['cash_paid']);
        $this->assertSame('10000.00', $previous['net_outstanding']);

        $current = $byYear['byYear'][$this->year->id];
        $this->assertSame('50000.00', $current['gross_invoiced']);
        $this->assertSame('20000.00', $current['cash_paid']);
        // The exact worked example from the task: 30,000, never 40,000
        // (which would be the case if previous-year debt leaked in).
        $this->assertSame('30000.00', $current['net_outstanding']);
    }

    // ----- 5/6. Previous-year debt surfaced separately, never merged ------

    public function test_previous_year_debt_is_visible_separately_and_not_added_to_selected_year(): void
    {
        $previousInvoice = $this->invoiceForYear($this->previousYear, '100000.00');
        $this->pay($previousInvoice, '90000.00');
        $currentInvoice = $this->invoiceForYear($this->year, '50000.00');
        $this->pay($currentInvoice, '20000.00');

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$this->year->id);

        $response->assertOk();
        $response->assertSee('30 000');
        $response->assertSee('Есть задолженность за предыдущие учебные годы');
        $response->assertSee('10 000');

        // Switching to the historical year shows ITS figures, not the
        // current year's.
        $historical = $this->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$this->previousYear->id);
        $historical->assertOk();
        $historical->assertSee('10 000');
    }

    public function test_fully_paid_historical_year_produces_no_false_previous_year_debt_warning(): void
    {
        $previousInvoice = $this->invoiceForYear($this->previousYear, '100000.00');
        $this->pay($previousInvoice, '100000.00');
        $this->invoiceForYear($this->year, '50000.00');

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$this->year->id);

        $response->assertOk();
        $response->assertDontSee('Есть задолженность за предыдущие учебные годы');
    }

    // ----- 8. Payment stays attached to the original invoice/year ----------

    public function test_payment_remains_attached_to_the_original_invoice_and_year(): void
    {
        $previousInvoice = $this->invoiceForYear($this->previousYear, '100000.00');
        $payment = $this->pay($previousInvoice, '40000.00');

        $this->assertSame($previousInvoice->id, $payment->invoice_id);
        $this->assertSame($this->previousYear->id, $payment->invoice->academic_year_id);

        $byYear = app(StudentFinanceSummaryService::class)->summarizeByYear($this->student);
        $this->assertTrue($byYear['byYear'][$this->previousYear->id]['payments']->contains('id', $payment->id));
        $this->assertArrayHasKey($this->year->id, $byYear['byYear'], 'the current year bucket still exists');
        $this->assertFalse(($byYear['byYear'][$this->year->id]['payments'] ?? collect())->contains('id', $payment->id));
    }

    // ----- 9. Refund reflected in the correct original year ----------------

    public function test_refund_remains_reflected_in_the_correct_original_year_and_never_shifts_years(): void
    {
        $previousInvoice = $this->invoiceForYear($this->previousYear, '100000.00');
        $payment = $this->pay($previousInvoice, '100000.00');
        $currentInvoice = $this->invoiceForYear($this->year, '50000.00');
        $this->pay($currentInvoice, '50000.00');

        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '15000.00', reason: 'Correction',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $byYear = app(StudentFinanceSummaryService::class)->summarizeByYear($this->student);

        $previous = $byYear['byYear'][$this->previousYear->id];
        $this->assertSame('85000.00', $previous['cash_paid'], 'net of the refund, in the year the refund belongs to');
        $this->assertSame('15000.00', $previous['net_outstanding']);

        // The current year, paid in full and never refunded, is completely
        // unaffected by a refund that happened in a different year.
        $current = $byYear['byYear'][$this->year->id];
        $this->assertSame('50000.00', $current['cash_paid']);
        $this->assertSame('0.00', $current['net_outstanding']);
    }

    // ----- 10/11. Identity and non-mutation on view --------------------------

    public function test_switching_selected_year_creates_no_finance_mutation(): void
    {
        $this->invoiceForYear($this->previousYear, '100000.00');
        $this->invoiceForYear($this->year, '50000.00');
        $studentCountBefore = Student::count();
        $invoiceCountBefore = Invoice::count();
        $paymentCountBefore = InvoicePayment::count();

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$this->previousYear->id)->assertOk();
        $this->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$this->year->id)->assertOk();
        $this->get(route('dashboard.students.finance', $this->student))->assertOk();

        $this->assertSame($studentCountBefore, Student::count());
        $this->assertSame($invoiceCountBefore, Invoice::count());
        $this->assertSame($paymentCountBefore, InvoicePayment::count());
    }

    public function test_an_unrecognized_academic_year_id_falls_back_to_the_safe_default_never_trusted_blindly(): void
    {
        $this->invoiceForYear($this->year, '50000.00');
        $otherStudentsYear = AcademicYear::create(['name' => '2030/2031', 'start_date' => '2030-08-01', 'end_date' => '2031-06-30', 'is_active' => false]);

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.finance', $this->student).'?academic_year_id='.$otherStudentsYear->id);

        $response->assertOk();
        // Falls back to the default (current active) year rather than a
        // year this student has no relationship to at all.
        $response->assertSee('50 000');
    }

    // ----- 12/13/14/15. PR #55/#56 regression + permissions on this page --

    public function test_pr56_add_service_and_pr55_enrollment_actions_remain_functional_on_the_year_aware_page(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));
        $response->assertOk();
        $response->assertSee('Добавить услугу');
        $response->assertSee(route('dashboard.students.add-service', $this->student), false);
        // Accountant still cannot create/update Enrollment — unchanged from PR #55.
        $response->assertDontSee('Зачислить на новый учебный год');
        $this->assertFalse($this->accountant->can('create enrollments'));

        $reception = $this->user('reception');
        $receptionResponse = $this->actingAs($reception)->get(route('dashboard.students.finance', $this->student));
        $receptionResponse->assertOk();
        $receptionResponse->assertSee('Зачислить на новый учебный год');
        $receptionResponse->assertDontSee('Добавить услугу');
    }

    public function test_unauthorized_roles_do_not_gain_finance_access_on_the_year_aware_page(): void
    {
        $teacher = $this->user('teacher');
        $this->actingAs($teacher)->get(route('dashboard.students.finance', $this->student))->assertStatus(302);
    }
}
