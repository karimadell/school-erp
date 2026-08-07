<?php

namespace Tests\Feature\Finance;

use App\Exceptions\Finance\BatchNotExecutableException;
use App\Models\BillingBatch;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\MassBillingExecutionService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Database\QueryException;
use Mockery;
use RuntimeException;

class MassBillingExecutionTest extends MassBillingTestCase
{
    private function preview(BillingBatch $batch): void
    {
        app(MassBillingPreviewService::class)->preview($batch);
        $batch->refresh();
    }

    private function execute(BillingBatch $batch, ?User $actor = null): BillingRun
    {
        return app(MassBillingExecutionService::class)->execute($batch, $actor ?? $this->accountant, '127.0.0.1', 'PHPUnit');
    }

    // ----- Successful execution -------------------------------------------

    public function test_previewed_batch_executes_and_generates_invoices_through_canonical_service(): void
    {
        $a = $this->enrolledStudent($this->classA, suffix: 'A1');
        $b = $this->enrolledStudent($this->classA, suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        $run = $this->execute($batch);

        // Two invoices, canonical amounts/dates/numbering via InvoiceIssuanceService.
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, InvoiceItem::count());
        foreach (Invoice::all() as $invoice) {
            $this->assertSame('1200.00', $invoice->total_amount);
            $this->assertSame('EGP', $invoice->currency);
            $this->assertSame('2026-09-01', $invoice->created_at->toDateString());
            $this->assertSame('2027-01-01', $invoice->due_date->toDateString());
            $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->invoice_number);
            $this->assertSame(1, $invoice->fees()->count()); // invoice_fee compatibility row
        }

        // Run record.
        $run->refresh();
        $this->assertSame(BillingRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(BillingRun::TRIGGER_MANUAL, $run->trigger_type);
        $this->assertSame($this->accountant->id, $run->executed_by);
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(2, $run->created_count);
        $this->assertSame(0, $run->skipped_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame('2400.00', $run->total_amount);
        $this->assertNotNull($run->finished_at);

        // Run items link to generated invoices.
        $this->assertSame(2, $run->items()->count());
        foreach ($run->items as $item) {
            $this->assertSame(BillingRunItem::STATUS_GENERATED, $item->status);
            $this->assertNotNull($item->invoice_id);
            $this->assertSame('1200.00', $item->amount);
            $this->assertSame(1, $item->quantity);
        }
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $run->items->pluck('student_id')->all());

        // Batch is completed.
        $batch->refresh();
        $this->assertSame(BillingBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame($this->accountant->id, $batch->executed_by);
    }

    public function test_quantity_multiplies_the_generated_invoice_total(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id], quantity: 3);
        $this->preview($batch);

        $this->execute($batch);

        $this->assertSame('3600.00', Invoice::sole()->total_amount); // 1200 × 3
    }

    // ----- Recalculation --------------------------------------------------

    public function test_execution_ignores_preview_price_and_recalculates_current_tariff(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);
        $this->assertSame('1200.00', $batch->expected_total_amount);

        // Tariff changes after preview; execution must use the new price.
        FeePrice::where('fee_id', $this->tuition->id)->update(['amount' => '1500.00']);

        $this->execute($batch);

        $this->assertSame('1500.00', Invoice::sole()->total_amount);
        // Preview figure is untouched/informational.
        $this->assertSame('1200.00', $batch->fresh()->expected_total_amount);
    }

    public function test_eligibility_change_after_preview_is_respected(): void
    {
        $stays = $this->enrolledStudent($this->classA, suffix: 'A1');
        $leaves = $this->enrolledStudent($this->classA, suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);
        $this->assertSame(2, $batch->eligible_count);

        // One student becomes ineligible between preview and execution.
        $leaves->enrollments()->update(['status' => 'withdrawn', 'is_active' => false]);

        $run = $this->execute($batch);

        $this->assertSame(1, Invoice::count());
        $this->assertSame($stays->id, Invoice::sole()->student_id);
        $this->assertSame(1, $run->created_count);
        $this->assertSame(1, $run->skipped_count);
        $skipped = $run->items()->where('student_id', $leaves->id)->sole();
        $this->assertSame(BillingRunItem::STATUS_SKIPPED, $skipped->status);
        $this->assertSame('enrollment_withdrawn', $skipped->skip_reason);
    }

    // ----- Business skips -------------------------------------------------

    public function test_missing_tariff_at_execution_is_skipped_not_failed(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        // Remove the covering tariff after preview.
        FeePrice::where('fee_id', $this->tuition->id)->update(['is_active' => false]);

        $run = $this->execute($batch);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(BillingRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame('no_tariff', $run->items()->sole()->skip_reason);
        $this->assertSame(BillingBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_registration_fee_already_billed_is_skipped_at_execution(): void
    {
        $regFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $regFee->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'start_date' => '2026-05-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $dup = $this->enrolledStudent($this->classA, suffix: 'Dup');
        $fresh = $this->enrolledStudent($this->classA, suffix: 'New');
        $this->seedRegistrationInvoice($dup, $regFee);

        $batch = $this->makeBatch(classIds: [$this->classA->id], fee: $regFee);
        $this->preview($batch);

        $run = $this->execute($batch);

        $this->assertSame('registration_duplicate', $run->items()->where('student_id', $dup->id)->sole()->skip_reason);
        $generated = $run->items()->where('student_id', $fresh->id)->sole();
        $this->assertSame(BillingRunItem::STATUS_GENERATED, $generated->status);
        // The pre-existing dup invoice + exactly one freshly generated one.
        $this->assertSame(2, Invoice::count());
    }

    // ----- Idempotency ----------------------------------------------------

    public function test_executing_a_completed_batch_again_creates_no_new_invoices(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);
        $this->execute($batch);
        $this->assertSame(1, Invoice::count());

        try {
            $this->execute($batch->fresh());
            $this->fail('Expected BatchNotExecutableException.');
        } catch (BatchNotExecutableException $exception) {
            $this->assertSame(BatchNotExecutableException::REASON_ALREADY_COMPLETED, $exception->reason);
        }

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, BillingRun::count());
    }

    public function test_a_failed_batch_cannot_silently_reissue(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $batch->update(['status' => BillingBatch::STATUS_FAILED]);

        $this->expectException(BatchNotExecutableException::class);
        $this->execute($batch);
    }

    public function test_repeated_post_execute_does_not_duplicate_invoices(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.execute', $batch))
            ->assertRedirect(route('dashboard.finance.mass-billing.show', $batch))
            ->assertSessionHas('success');

        // A second POST (refresh/replay) must not generate more invoices.
        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.execute', $batch))
            ->assertRedirect(route('dashboard.finance.mass-billing.show', $batch))
            ->assertSessionHas('error');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, BillingRun::count());
    }

    public function test_database_uniqueness_prevents_duplicate_invoice_linkage(): void
    {
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $run = $batch->runs()->create(['status' => BillingRun::STATUS_PROCESSING, 'executed_by' => $this->accountant->id]);
        $invoice = $this->bareInvoice($student);

        $run->items()->create(['student_id' => $student->id, 'invoice_id' => $invoice->id, 'status' => BillingRunItem::STATUS_GENERATED, 'quantity' => 1]);

        $this->expectException(QueryException::class);
        $run->items()->create(['student_id' => $student->id, 'invoice_id' => $invoice->id, 'status' => BillingRunItem::STATUS_GENERATED, 'quantity' => 1]);
    }

    // ----- Atomicity ------------------------------------------------------

    public function test_unexpected_failure_rolls_back_all_invoices_and_records_failure(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $this->enrolledStudent($this->classA, suffix: 'A2');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        // Real issuance for the first student, an unexpected throw for the second.
        $real = $this->app->make(InvoiceIssuanceService::class);
        $calls = 0;
        $failing = Mockery::mock(InvoiceIssuanceService::class);
        $failing->shouldReceive('issue')->andReturnUsing(function (...$args) use ($real, &$calls) {
            $calls++;
            if ($calls === 1) {
                return $real->issue(...$args);
            }
            throw new RuntimeException('Simulated issuance failure');
        });
        $this->app->instance(InvoiceIssuanceService::class, $failing);

        try {
            $this->execute($batch);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated issuance failure', $exception->getMessage());
        }

        // Zero invoices survive; nothing partially generated.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, BillingRunItem::whereNotNull('invoice_id')->count());
        $this->assertSame(0, BillingRunItem::count());

        // Batch + run recorded as failed with a compact, non-sensitive summary.
        $batch->refresh();
        $this->assertSame(BillingBatch::STATUS_FAILED, $batch->status);
        $run = $batch->latestRun;
        $this->assertSame(BillingRun::STATUS_FAILED, $run->status);
        $this->assertSame(['code' => MassBillingExecutionService::FAILURE_CODE], $run->failure_summary);
    }

    // ----- Authorization --------------------------------------------------

    public function test_unauthorized_user_without_execute_permission_gets_403(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        // Administrative user with invoice management but WITHOUT the dedicated
        // execute permission.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');
        $user->givePermissionTo('manage invoices');

        $this->actingAs($user)
            ->post(route('dashboard.finance.mass-billing.execute', $batch))
            ->assertForbidden();

        $this->assertSame(0, Invoice::count());
    }

    public function test_authorized_accountant_can_execute(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        $this->preview($batch);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.execute', $batch))
            ->assertRedirect(route('dashboard.finance.mass-billing.show', $batch))
            ->assertSessionHas('success');

        $this->assertSame(1, Invoice::count());
    }

    // ----- Fixtures -------------------------------------------------------

    private function seedRegistrationInvoice(Student $student, Fee $fee): void
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '7000.00', 'total_amount' => '7000.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '7000.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru,
            'unit_price' => '7000.00', 'quantity' => 1, 'amount' => '7000.00', 'paid_amount' => '0.00', 'remaining_amount' => '7000.00',
        ]);
    }

    private function bareInvoice(Student $student): Invoice
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '1.00', 'total_amount' => '1.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();

        return $invoice;
    }
}
