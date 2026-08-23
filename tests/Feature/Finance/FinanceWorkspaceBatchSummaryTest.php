<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PromiseToPay;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplication;
use App\Models\TariffAdjustment;
use App\Services\Finance\StudentFinanceSummaryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class FinanceWorkspaceBatchSummaryTest extends FinanceOperationsTestCase
{
    public function test_batch_summaries_match_single_student_accounting_semantics(): void
    {
        $students = collect([$this->student, $this->student('Петров'), $this->student('Сидоров')]);
        $invoices = $students->mapWithKeys(fn (Student $student) => [
            $student->id => $this->invoiceFor($student, '1000.00'),
        ]);

        PromiseToPay::create([
            'student_id' => $students[1]->id,
            'invoice_id' => $invoices[$students[1]->id]->id,
            'promised_amount' => '250.00',
            'expected_payment_date' => '2027-01-01',
            'status' => PromiseToPay::STATUS_OPEN,
            'created_by' => $this->accountant->id,
        ]);
        $sourceItem = InvoiceItem::create([
            'invoice_id' => $invoices[$students[2]->id]->id,
            'fee_id' => $this->fee->id,
            'description' => 'Batch summary coverage',
            'unit_price' => '1200.00',
            'quantity' => 1,
            'amount' => '1200.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '1200.00',
        ]);
        $price = $this->fee->prices()->firstOrFail();
        $coverage = ServiceCoverage::create([
            'student_id' => $students[2]->id,
            'fee_id' => $this->fee->id,
            'invoice_item_id' => $sourceItem->id,
            'fee_price_id' => $price->id,
            'coverage_start' => '2026-08-01',
            'coverage_end' => '2027-06-30',
            'billing_unit' => 'monthly',
            'payment_period' => 'yearly',
            'original_unit_price' => '1200.00',
            'created_by' => $this->accountant->id,
        ]);
        $adjustment = TariffAdjustment::forceCreate([
            'student_id' => $students[2]->id,
            'fee_id' => $this->fee->id,
            'service_coverage_id' => $coverage->id,
            'previous_fee_price_id' => null,
            'new_fee_price_id' => $price->id,
            'status' => TariffAdjustment::STATUS_POSTED,
            'kind' => 'credit',
            'total_difference' => '-300.00',
            'currency' => 'EGP',
            'approved_by' => $this->accountant->id,
            'approved_at' => now(),
        ]);
        $credit = StudentCredit::create([
            'student_id' => $students[2]->id,
            'source_adjustment_id' => $adjustment->id,
            'original_amount' => '300.00',
            'consumed_amount' => '100.00',
            'available_amount' => '200.00',
            'status' => StudentCredit::STATUS_PARTIAL,
        ]);
        StudentCreditApplication::create([
            'student_credit_id' => $credit->id,
            'student_id' => $students[2]->id,
            'invoice_id' => $invoices[$students[2]->id]->id,
            'amount' => '100.00',
            'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'applied_by' => $this->accountant->id,
            'applied_at' => now(),
        ]);

        $service = app(StudentFinanceSummaryService::class);
        $single = $students->mapWithKeys(fn (Student $student) => [
            $student->id => $service->summarize($student->fresh()),
        ]);
        $batch = $service->summarizeMany(Student::whereKey($students->pluck('id'))->get());

        foreach ($students as $student) {
            $this->assertSame($this->amounts($single[$student->id]), $this->amounts($batch[$student->id]));
            $this->assertSame(
                $single[$student->id]['adjustments']->pluck('id')->all(),
                $batch[$student->id]['adjustments']->pluck('id')->all(),
            );
            $this->assertSame(
                $single[$student->id]['promises']->pluck('id')->all(),
                $batch[$student->id]['promises']->pluck('id')->all(),
            );
        }
        $this->assertSame('250.00', $batch[$students[1]->id]['promised']);
        $this->assertSame('100.00', $batch[$students[2]->id]['credit_applied']);
        $this->assertSame('200.00', $batch[$students[2]->id]['available_credit']);
        $this->assertSame('900.00', $batch[$students[2]->id]['net_outstanding']);
        $this->assertSame('-700.00', $batch[$students[2]->id]['net_student_balance']);
    }

    public function test_phase_two_query_count_is_constant_as_student_count_grows(): void
    {
        $this->student('Петров');
        $smallCount = $this->phaseTwoQueryCount(fn () => $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk());

        collect([
            $this->student('Сидоров'),
            $this->student('Смирнов'),
            $this->student('Кузнецов'),
        ]);
        $largeCount = $this->phaseTwoQueryCount(fn () => $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk());

        $this->assertSame(4, $smallCount);
        $this->assertSame($smallCount, $largeCount);
    }

    private function student(string $lastName): Student
    {
        return Student::create([
            'last_name_ru' => $lastName,
            'first_name_ru' => 'Тест',
            'phone' => '+201'.str_pad((string) Student::count(), 9, '0', STR_PAD_LEFT),
            'status' => 'registration_completed',
        ]);
    }

    private function invoiceFor(Student $student, string $total): Invoice
    {
        $invoice = Invoice::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'customer_name' => $student->full_name,
            'currency' => 'EGP',
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'discount_amount' => '0.00',
            'paid_amount' => '0.00',
            'remaining_amount' => $total,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01',
            'created_by' => $this->accountant->id,
        ]);
        $invoice->forceFill(['invoice_number' => Invoice::numberFor($invoice->id, '2026')])->save();

        return $invoice;
    }

    private function phaseTwoQueryCount(callable $callback): int
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (preg_match('/from ["`](tariff_adjustments|promise_to_pays|student_credits|student_credit_applications)["`]/', $query->sql)) {
                $count++;
            }
        });
        $callback();

        return $count;
    }

    /** @return array<string, string> */
    private function amounts(array $summary): array
    {
        return collect($summary)->only([
            'gross_invoiced', 'cash_paid', 'gross_remaining', 'credit_applied',
            'available_credit', 'net_outstanding', 'net_student_balance',
            'promised', 'overdue_net', 'invoiced', 'paid', 'remaining', 'overdue',
        ])->all();
    }
}
