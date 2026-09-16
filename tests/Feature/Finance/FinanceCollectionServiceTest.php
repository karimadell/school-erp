<?php

namespace Tests\Feature\Finance;

use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Finance\FinanceCollectionService;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unified Collection foundation (PR B) — characterization tests for
 * FinanceCollectionService, the new backend/domain orchestration layer
 * above the existing, unchanged accounting engines
 * (InvoiceIssuanceService/InvoicePaymentService/InvoiceRefundService/
 * CashSessionService). No UI, no controller, no route exists yet — this
 * calls the service directly, exactly as a future PR C controller will.
 */
class FinanceCollectionServiceTest extends FinanceOperationsTestCase
{
    private function service(): FinanceCollectionService
    {
        return app(FinanceCollectionService::class);
    }

    private function booksFee(string $amount = '500.00'): Fee
    {
        return Fee::create(['name_ru' => 'Учебники', 'category' => Fee::CATEGORY_BOOKS, 'amount' => $amount, 'is_active' => true]);
    }

    // ----- A. migration/model relationships -----

    public function test_migration_and_model_relationships_work(): void
    {
        $collection = FinanceCollection::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'x'),
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'created_by' => $this->accountant->id,
            'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
            'status' => FinanceCollection::STATUS_COMPLETED,
        ]);

        $this->assertTrue($collection->student->is($this->student));
        $this->assertTrue($collection->academicYear->is($this->year));
        $this->assertTrue($collection->creator->is($this->accountant));
        $this->assertTrue($collection->cashAccount->is($this->cash));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $collection->invoices);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $collection->invoicePayments);
    }

    // ----- B. collection number -----

    public function test_collection_number_is_generated_uniquely_and_stably(): void
    {
        $a = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [], 'new_services' => [],
        ], $this->accountant);

        $studentB = $this->makeAnotherStudent();
        $b = $this->service()->collect([
            'student_id' => $studentB->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [], 'new_services' => [],
        ], $this->accountant);

        $this->assertNotSame($a->collection_number, $b->collection_number);
        $this->assertMatchesRegularExpression('/^RCPT-\d{4}-\d{6}$/', $a->collection_number);
        // Stable: refetching the same row returns the same number.
        $this->assertSame($a->collection_number, FinanceCollection::find($a->id)->collection_number);
    }

    // ----- C/D. derived received total, multiple InvoicePayment rows -----

    public function test_derived_received_total_equals_sum_of_linked_payments_across_multiple_rows(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('300.00');
        $fee = $this->booksFee('200.00');

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '100.00'],
            ],
            'new_services' => [
                ['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '150.00'],
            ],
        ], $this->accountant);

        $this->assertCount(2, $collection->invoicePayments);
        $this->assertSame('250.00', $collection->receivedTotal());
        $this->assertSame('250.00', bcadd((string) $collection->invoicePayments()->sum('amount'), '0', 2));
    }

    // ----- E/F/Q. one Student, one AcademicYear -----

    public function test_existing_obligation_from_a_different_student_is_rejected(): void
    {
        $other = $this->makeAnotherStudent();
        $foreignInvoice = app(InvoiceIssuanceService::class)->issue($other, [
            'student_id' => $other->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);

        $this->expectException(ValidationException::class);
        $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $foreignInvoice->id, 'receive_now_amount' => '10.00'],
            ],
        ], $this->accountant);
    }

    public function test_existing_obligation_from_a_different_academic_year_is_rejected(): void
    {
        // A historical (already chronologically closed) year's own writes
        // are locked by AcademicYearLockObserver regardless of is_active —
        // the established, documented escape hatch for a test building a
        // historical-year fixture is AcademicYearLock::withoutLock(), the
        // same one QuarterSeeder itself uses for the identical reason.
        [$otherYear, $oldYearInvoice] = \App\Support\AcademicYearLock::withoutLock(function () {
            $otherYear = \App\Models\AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
            Enrollment::create([
                'student_id' => $this->student->id, 'academic_year_id' => $otherYear->id,
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id, 'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
                'academic_year' => $otherYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
                'status' => 'active', 'is_active' => true,
            ]);
            // Old year's invoice needs its own year to be active at
            // issuance time only (issue()'s own gate) — briefly flip,
            // issue, then restore, so the fixture invoice exists without
            // permanently leaving two simultaneously-active years.
            $otherYear->forceFill(['is_active' => true])->save();
            $this->year->refresh();
            // A flat-Fee.amount category (no FeePrice/tariff row at all) —
            // $this->fee's own FeePrice is only date-scoped to $this->year
            // (2026/2027) and would not resolve for a 2025-09-01 pricing
            // date against a different academic year.
            $oldYearFee = Fee::create(['name_ru' => 'Учебники (старый год)', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '400.00', 'is_active' => true]);
            $oldYearInvoice = app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $otherYear->id,
                'due_date' => '2026-06-30', 'pricing_date' => '2025-09-01',
                'items' => [['fee_id' => $oldYearFee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
                'payment_type' => 'one_time',
            ], $this->accountant);
            $otherYear->forceFill(['is_active' => false])->save();
            $this->year->forceFill(['is_active' => true])->save();

            return [$otherYear, $oldYearInvoice];
        });

        $this->expectException(ValidationException::class);
        $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $oldYearInvoice->id, 'receive_now_amount' => '10.00'],
            ],
        ], $this->accountant);
    }

    public function test_new_charges_are_always_issued_against_the_collections_own_single_academic_year(): void
    {
        $fee = $this->booksFee('100.00');
        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '0.00']],
        ], $this->accountant);

        $invoice = $collection->linkedInvoices()->sole();
        $this->assertSame($this->year->id, $invoice->academic_year_id);
    }

    // ----- G/H. existing obligation partial payment -----

    public function test_existing_obligation_partial_payment_succeeds_and_leaves_correct_remaining_debt(): void
    {
        $invoice = $this->issueSimpleInvoice('1000.00');

        $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $invoice->id, 'receive_now_amount' => '400.00'],
            ],
        ], $this->accountant);

        $invoice->refresh();
        $this->assertSame('400.00', (string) $invoice->paid_amount);
        $this->assertSame('600.00', (string) $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
    }

    // ----- I. multiple services, independent partial amounts -----

    public function test_multiple_new_services_each_receive_their_own_explicit_partial_amount(): void
    {
        $feeA = $this->booksFee('500.00');
        $feeB = Fee::create(['name_ru' => 'Кружок', 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => '300.00', 'is_active' => true]);

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [
                ['fee_id' => $feeA->id, 'quantity' => 1, 'receive_now_amount' => '200.00'],
                ['fee_id' => $feeB->id, 'quantity' => 1, 'receive_now_amount' => '300.00'],
            ],
        ], $this->accountant);

        $invoice = $collection->linkedInvoices()->sole();
        $items = $invoice->items->sortBy('id')->values();
        $payment = $collection->invoicePayments->sole();
        $allocationByItemId = $payment->allocations->keyBy('invoice_item_id');

        $this->assertSame('200.00', (string) $allocationByItemId[$items[0]->id]->amount);
        $this->assertSame('300.00', (string) $allocationByItemId[$items[1]->id]->amount);
        $this->assertSame('500.00', $collection->receivedTotal());
    }

    // ----- J. canonical charge vs receive-now -----

    public function test_new_service_canonical_charge_500_receive_now_300_leaves_200_remaining(): void
    {
        $fee = $this->booksFee('500.00');

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [
                ['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '300.00'],
            ],
        ], $this->accountant);

        $invoice = $collection->linkedInvoices()->sole()->fresh();
        $item = $invoice->items->sole();
        $payment = $collection->invoicePayments->sole();

        $this->assertSame('500.00', (string) $item->amount);
        $this->assertSame('300.00', (string) $payment->amount);
        $this->assertSame('300.00', (string) $payment->allocations->sole()->amount);
        $this->assertSame('200.00', (string) $invoice->remaining_amount);
        $this->assertSame('200.00', bcsub((string) $invoice->total_amount, (string) $invoice->paid_amount, 2));
    }

    // ----- K/U. client cannot override canonical price -----

    public function test_client_supplied_price_fields_on_a_new_service_are_silently_ignored(): void
    {
        $fee = $this->booksFee('500.00');

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [
                ['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '0.00', 'amount' => '1.00', 'price' => '1.00', 'unit_price' => '1.00'],
            ],
        ], $this->accountant);

        $item = $collection->linkedInvoices()->sole()->items->sole();
        $this->assertSame('500.00', (string) $item->amount);
    }

    // ----- L. mixed existing + new atomically -----

    public function test_mixed_existing_obligation_and_new_service_works_atomically_in_one_collection(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('1000.00');
        $fee = $this->booksFee('500.00');

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '400.00'],
            ],
            'new_services' => [
                ['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '500.00'],
            ],
        ], $this->accountant);

        $this->assertSame('900.00', $collection->receivedTotal());
        $this->assertCount(2, $collection->invoicePayments);
        $this->assertCount(2, $collection->linkedInvoices());
    }

    // ----- M. failure on a later line rolls back everything -----

    public function test_failure_on_a_later_line_rolls_back_the_whole_collection_attempt(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('1000.00');

        try {
            $this->service()->collect([
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
                'existing_obligations' => [
                    ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '400.00'],
                    // Overpayment against the (already-reduced-by-the-line-
                    // above, but that reduction is inside the SAME still-
                    // open transaction) invoice fails closed here.
                    ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '900.00'],
                ],
            ], $this->accountant);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $existingInvoice->refresh();
        $this->assertSame('0.00', (string) $existingInvoice->paid_amount);
        $this->assertSame(0, InvoicePayment::query()->where('invoice_id', $existingInvoice->id)->count());
        $this->assertSame(0, FinanceCollection::query()->count());
    }

    // ----- N/O. idempotency -----

    public function test_retry_with_the_same_idempotency_token_does_not_duplicate_money(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('1000.00');
        $payload = [
            'idempotency_token' => 'collection-retry-token',
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '400.00'],
            ],
        ];

        $first = $this->service()->collect($payload, $this->accountant);
        $second = $this->service()->collect($payload, $this->accountant);

        $this->assertSame($first->id, $second->id);
        $existingInvoice->refresh();
        $this->assertSame('400.00', (string) $existingInvoice->paid_amount);
        $this->assertSame(1, FinanceCollection::query()->count());
    }

    public function test_same_idempotency_token_with_a_materially_different_payload_is_rejected(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('1000.00');
        $this->service()->collect([
            'idempotency_token' => 'collection-conflict-token',
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '400.00'],
            ],
        ], $this->accountant);

        $this->expectException(ValidationException::class);
        $this->service()->collect([
            'idempotency_token' => 'collection-conflict-token',
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '999.00'],
            ],
        ], $this->accountant);
    }

    // ----- P. duplicate protection (database-level, as far as this environment can prove) -----

    public function test_the_idempotency_key_column_itself_rejects_a_duplicate_insert(): void
    {
        $key = (string) Str::uuid();
        FinanceCollection::create([
            'idempotency_key' => $key, 'payload_hash' => hash('sha256', 'a'),
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'status' => FinanceCollection::STATUS_COMPLETED,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FinanceCollection::create([
            'idempotency_key' => $key, 'payload_hash' => hash('sha256', 'b'),
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'status' => FinanceCollection::STATUS_COMPLETED,
        ]);
    }

    // ----- R. narrow annual-registration authorization -----

    public function test_accountant_is_authorized_to_establish_annual_registration_for_a_returning_student(): void
    {
        $newStudent = $this->makeAnotherStudent();
        $newYear = \App\Models\AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $newYear->forceFill(['is_active' => true])->save();
        $this->year->refresh();

        $collection = $this->service()->collect([
            'student_id' => $newStudent->id, 'academic_year_id' => $newYear->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'annual_registration' => [
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
                'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id,
                'class_id' => $this->enrollment->class_id,
            ],
        ], $this->accountant);

        $this->assertTrue($collection->exists);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $newStudent->id, 'academic_year_id' => $newYear->id, 'is_active' => true,
        ]);
    }

    public function test_unauthorized_role_is_denied_annual_registration_authorization(): void
    {
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $newStudent = $this->makeAnotherStudent();
        $newYear = \App\Models\AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $newYear->forceFill(['is_active' => true])->save();
        $this->year->refresh();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->collect([
            'student_id' => $newStudent->id, 'academic_year_id' => $newYear->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'annual_registration' => [
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
                'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id,
                'class_id' => $this->enrollment->class_id,
            ],
        ], $reception);
    }

    public function test_granting_the_narrow_permission_does_not_grant_generic_enrollment_crud_permissions(): void
    {
        $this->assertFalse($this->accountant->can('create enrollments'));
        $this->assertFalse($this->accountant->can('update enrollments'));
        $this->assertTrue($this->accountant->can('register students for year'));
    }

    public function test_cashier_is_also_authorized_for_annual_registration(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');
        $this->assertTrue($cashier->can('register students for year'));
    }

    // ----- S. returning student new-year enrollment never touches old year -----

    public function test_returning_student_gets_a_new_enrollment_without_changing_the_old_one(): void
    {
        $newYear = \App\Models\AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $newYear->forceFill(['is_active' => true])->save();
        $this->year->refresh();
        $oldGradeId = $this->enrollment->grade_id;

        $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $newYear->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'annual_registration' => [
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
                'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id,
                'class_id' => $this->enrollment->class_id,
            ],
        ], $this->accountant);

        $this->enrollment->refresh();
        $this->assertSame($oldGradeId, $this->enrollment->grade_id);
        $this->assertSame($this->year->id, $this->enrollment->academic_year_id);
        $this->assertSame(2, Enrollment::query()->where('student_id', $this->student->id)->count());
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'academic_year_id' => $newYear->id]);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'academic_year_id' => $this->year->id]);
    }

    // ----- T. existing refund behavior still works for a collection-linked payment -----

    public function test_refund_still_works_for_a_payment_linked_to_a_collection(): void
    {
        $invoice = $this->issueSimpleInvoice('1000.00');
        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [
                ['invoice_id' => $invoice->id, 'receive_now_amount' => '400.00'],
            ],
        ], $this->accountant);

        $payment = $collection->invoicePayments->sole();
        $refund = app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '100.00',
            reason: 'Возврат части оплаты',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            cashAccountId: $this->cash->id,
        );

        $this->assertSame('100.00', (string) $refund->amount);
        $invoice->refresh();
        $this->assertSame('300.00', (string) $invoice->paid_amount);
    }

    // ----- V. dynamically configured generic Fee, no hardcoded list -----

    public function test_a_freshly_configured_generic_fee_flows_through_without_any_hardcoded_category_list(): void
    {
        $fee = Fee::create(['name_ru' => 'Новая услуга ' . Str::random(6), 'category' => Fee::CATEGORY_OTHER, 'amount' => '77.00', 'is_active' => true]);

        $collection = $this->service()->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '77.00']],
        ], $this->accountant);

        $item = $collection->linkedInvoices()->sole()->items->sole();
        $this->assertSame('77.00', (string) $item->amount);
        $this->assertSame('77.00', $collection->receivedTotal());
    }

    // ----- helpers -----

    private function issueSimpleInvoice(string $amount): Invoice
    {
        $fee = Fee::create(['name_ru' => 'Доп. услуга ' . Str::random(6), 'category' => Fee::CATEGORY_BOOKS, 'amount' => $amount, 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    private function makeAnotherStudent(): \App\Models\Student
    {
        $student = \App\Models\Student::create([
            'last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'patronymic_ru' => 'Петрович',
            'phone' => '+201009998877', 'class_id' => $this->enrollment->class_id, 'status' => 'registration_completed',
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->enrollment->enrollment_mode_id, 'stage_id' => $this->enrollment->stage_id,
            'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'academic_year' => $this->year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]);

        return $student;
    }
}
