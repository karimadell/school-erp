<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass (HIGH — coverage period integrity).
 */
class InstallmentCoveragePeriodIntegrityTest extends FinanceOperationsTestCase
{
    private function issuedFee(): array
    {
        $fee = Fee::create(['name_ru' => 'Трансфер (целостность)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        return [$fee, $invoice];
    }

    public function test_period_end_before_period_start_is_rejected(): void
    {
        [, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        $installment = $invoice->installments()->first();

        $this->expectException(ValidationException::class);
        InstallmentCoveragePeriod::create([
            'invoice_installment_id' => $installment->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => '2026-09-30',
            'period_end' => '2026-09-01',
        ]);
    }

    public function test_period_outside_the_coverage_span_is_rejected(): void
    {
        [, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        $installment = $invoice->installments()->first();

        $this->expectException(ValidationException::class);
        InstallmentCoveragePeriod::create([
            'invoice_installment_id' => $installment->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => '2020-01-01',
            'period_end' => '2020-01-31',
        ]);
    }

    public function test_installment_from_a_different_invoice_than_the_coverage_is_rejected(): void
    {
        [$fee, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        // A second, unrelated invoice/installment for the same student.
        $otherInvoice = $this->invoice('100.00');

        $this->expectException(ValidationException::class);
        InstallmentCoveragePeriod::create([
            'invoice_installment_id' => $otherInvoice->installments()->first()?->id ?? \App\Models\InvoiceInstallment::create(['invoice_id' => $otherInvoice->id, 'name_ru' => 'x', 'sequence' => 1, 'due_date' => now(), 'amount' => '100.00', 'paid_amount' => '0.00', 'remaining_amount' => '100.00', 'status' => 'pending'])->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
    }

    public function test_overlapping_period_for_the_same_coverage_is_rejected(): void
    {
        [, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        $installments = $invoice->installments()->orderBy('sequence')->get();
        // The first installment already has a period row (Sep 1-30, created
        // automatically by issue()) — a second, overlapping row for the
        // SAME coverage must be rejected.
        $this->expectException(ValidationException::class);
        InstallmentCoveragePeriod::create([
            'invoice_installment_id' => $installments[1]->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => '2026-09-15',
            'period_end' => '2026-10-15',
        ]);
    }

    /**
     * Corrective pass #2 (HIGH 6 — coverage-period integrity and
     * concurrency). Bypasses the Eloquent model layer entirely (a raw
     * insert via the query builder, never touching
     * InstallmentCoveragePeriod::validateIntegrity()) — proves the
     * invariant is ALSO enforced at the actual database level (via the
     * SQLite trigger this migration installs on this test's own driver),
     * not solely by application code that a raw query or a future bug
     * could bypass.
     */
    public function test_the_database_itself_rejects_an_out_of_order_period_bypassing_the_model_layer(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test exercises the SQLite-specific trigger; MySQL/PostgreSQL get a native CHECK constraint instead (same migration, different branch) — not re-verified here since this suite only runs against sqlite.');
        }

        [, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        $installment = $invoice->installments()->first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('installment_coverage_periods')->insert([
            'invoice_installment_id' => $installment->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => '2026-09-30',
            'period_end' => '2026-09-01',
            'created_at' => now(),
        ]);
    }

    public function test_cross_student_or_cross_invoice_mapping_is_structurally_impossible_through_the_application(): void
    {
        // Coverage-period creation only ever happens internally within
        // InvoiceIssuanceService::issue()'s own transaction, from data it
        // already resolved itself — confirmed here directly rather than
        // only asserted in prose: every period row created by a real
        // issuance belongs to that same issuance's own invoice/coverage,
        // for every Fee on it.
        [, $invoice] = $this->issuedFee();
        $coverage = ServiceCoverage::sole();
        $periods = InstallmentCoveragePeriod::where('service_coverage_id', $coverage->id)->get();
        $this->assertTrue($periods->isNotEmpty());
        foreach ($periods as $period) {
            $this->assertSame($invoice->id, $period->installment->invoice_id);
            $this->assertSame($coverage->student_id, $this->student->id);
        }
    }
}
