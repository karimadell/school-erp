<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Student;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\StudentObligationsViewModel;
use Illuminate\Support\Str;

/**
 * Unified Cashier Workspace (PR C1) — characterization tests for
 * StudentObligationsViewModel, written before it is ever relied on by the
 * workspace controller/view, per the approved design. Read-only: every
 * test only issues invoices/payments through the existing, unchanged
 * InvoiceIssuanceService/InvoicePaymentService, then asserts what the view
 * model reports — it never asserts anything about money movement itself
 * (that remains FinanceCollectionServiceTest's job).
 */
class StudentObligationsViewModelTest extends FinanceOperationsTestCase
{
    private function viewModel(): StudentObligationsViewModel
    {
        return app(StudentObligationsViewModel::class);
    }

    private function issueSingleItemInvoice(string $amount, ?AcademicYear $year = null, ?Student $student = null): \App\Models\Invoice
    {
        $year ??= $this->year;
        $student ??= $this->student;
        $fee = Fee::create(['name_ru' => 'Учебники', 'category' => Fee::CATEGORY_BOOKS, 'amount' => $amount, 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($student, [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    public function test_correct_remaining_amount_for_an_unpaid_single_item_invoice(): void
    {
        $this->issueSingleItemInvoice('1000.00');

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertSame('1000.00', $rows[0]['charged']);
        $this->assertSame('1000.00', $rows[0]['remaining']);
        $this->assertSame('Учебники', $rows[0]['label']);
        $this->assertFalse($rows[0]['ambiguous']);
    }

    public function test_partially_paid_service_shows_correct_remaining(): void
    {
        $invoice = $this->issueSingleItemInvoice('1000.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertSame('600.00', $rows[0]['remaining']);
    }

    public function test_fully_paid_service_is_excluded_from_outstanding_rows(): void
    {
        $invoice = $this->issueSingleItemInvoice('500.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '500.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(0, $rows);
    }

    public function test_multiple_invoices_same_student_and_year_all_appear(): void
    {
        $this->issueSingleItemInvoice('100.00');
        $this->issueSingleItemInvoice('200.00');

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(2, $rows);
        $this->assertSame('300.00', bcadd((string) $rows->sum('remaining'), '0', 2));
    }

    public function test_another_students_invoice_is_excluded(): void
    {
        $otherStudent = Student::create([
            'last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'patronymic_ru' => 'Петрович',
            'phone' => '+201009998877', 'class_id' => $this->enrollment->class_id, 'status' => 'registration_completed',
        ]);
        Enrollment::create([
            'student_id' => $otherStudent->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->enrollment->enrollment_mode_id, 'stage_id' => $this->enrollment->stage_id,
            'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'academic_year' => $this->year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]);
        $this->issueSingleItemInvoice('999.00', student: $otherStudent);
        $this->issueSingleItemInvoice('100.00');

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertSame('100.00', $rows[0]['remaining']);
    }

    public function test_another_academic_years_invoice_is_excluded(): void
    {
        $otherYear = \App\Support\AcademicYearLock::withoutLock(function () {
            $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
            Enrollment::create([
                'student_id' => $this->student->id, 'academic_year_id' => $otherYear->id,
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id, 'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
                'academic_year' => $otherYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
                'status' => 'active', 'is_active' => true,
            ]);
            $otherYear->forceFill(['is_active' => true])->save();
            $this->year->refresh();
            $this->issueSingleItemInvoice('777.00', year: $otherYear);
            $otherYear->forceFill(['is_active' => false])->save();
            $this->year->forceFill(['is_active' => true])->save();

            return $otherYear;
        });
        $this->issueSingleItemInvoice('100.00');

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertSame('100.00', $rows[0]['remaining']);

        $otherYearRows = $this->viewModel()->forStudentYear($this->student, $otherYear);
        $this->assertCount(1, $otherYearRows);
        $this->assertSame('777.00', $otherYearRows[0]['remaining']);
    }

    public function test_shared_single_installment_mixed_billing_case_shows_per_service_remaining(): void
    {
        // Two 'once'-strategy items under payment_type=mixed share ONE
        // installment (InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME)
        // — exactly one outstanding installment, so both remain
        // individually submittable.
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '300.00', 'is_active' => true]);
        $activity = Fee::create(['name_ru' => 'Кружок', 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => '250.00', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [
                ['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => null, '_billing_strategy' => 'once', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $activity->id, 'grade_group' => null, 'payment_period' => null, '_billing_strategy' => 'once', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
            ],
            'payment_type' => 'mixed',
        ], $this->accountant);
        $this->assertSame(1, $invoice->installments()->count());

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(2, $rows);
        $labels = $rows->pluck('label')->sort()->values();
        $this->assertSame(['Кружок', 'Обучение'], $labels->all());
        $this->assertTrue($rows->every(fn (array $row) => $row['submittable']));
        // No double-counting: the sum of per-service rows equals the
        // invoice's own total exactly.
        $this->assertSame((string) $invoice->total_amount, bcadd((string) $rows->sum('remaining'), '0', 2));
    }

    public function test_a_genuinely_multi_installment_obligation_is_shown_but_not_submittable(): void
    {
        // A calendar-billed Tuition spanning the whole year issues many
        // installments (one per month) — record() cannot auto-resolve
        // which one an item-level allocation belongs to without an
        // explicit installment_id (verified empirically, see
        // StudentObligationsViewModel's own docblock), so this must be
        // shown for awareness only, never offered for item-level
        // Unified Collection submission.
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        \App\Models\FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'monthly', 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
        $this->assertGreaterThan(1, $invoice->installments()->count());

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['submittable']);
        $this->assertSame((string) $invoice->remaining_amount, $rows[0]['remaining']);
    }

    public function test_ambiguous_multi_item_invoice_falls_back_to_one_honest_whole_invoice_row(): void
    {
        $feeA = Fee::create(['name_ru' => 'Услуга А', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '100.00', 'is_active' => true]);
        $feeB = Fee::create(['name_ru' => 'Услуга Б', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '150.00', 'is_active' => true]);
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [
                ['fee_id' => $feeA->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $feeB->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
            ],
            'payment_type' => 'one_time',
        ], $this->accountant);
        // record() itself refuses to create an unallocated payment against
        // a multi-item invoice (Phase 1C) — genuine ambiguity only ever
        // exists on a historical row written directly, matching the
        // established pattern in FinanceV2Phase1ERefundCleanAllocationTest.
        $legacyPayment = \App\Models\InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '50.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        \App\Models\CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $legacyPayment->id, 'amount' => '50.00',
            'type' => \App\Models\CashTransaction::TYPE_IN, 'category' => \App\Models\CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        $rows = $this->viewModel()->forStudentYear($this->student, $this->year);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['ambiguous']);
        $this->assertNull($rows[0]['invoice_item_id']);
        $this->assertSame('200.00', $rows[0]['remaining']);
    }
}
