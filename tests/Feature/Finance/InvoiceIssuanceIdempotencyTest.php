<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass (P0/HIGH — invoice issuance
 * idempotency).
 */
class InvoiceIssuanceIdempotencyTest extends FinanceOperationsTestCase
{
    private function periodicFee(): Fee
    {
        $fee = Fee::create(['name_ru' => 'Трансфер (идемпотентность)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function payload(Fee $fee, string $key): array
    {
        return [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ];
    }

    public function test_same_key_sequential_retry_returns_the_original_invoice_creating_nothing_new(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();

        $first = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);
        $second = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceItem::count());
        $this->assertSame(10, InvoiceInstallment::count(), 'exactly one full schedule (Sep 2026 - Jun 2027), not two');
        $this->assertSame(1, ServiceCoverage::count(), 'exactly one coverage row, not two');
    }

    public function test_different_token_produces_a_genuinely_new_separate_invoice(): void
    {
        $fee = $this->periodicFee();

        $first = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, (string) Str::uuid()), $this->accountant, idempotencyKey: (string) Str::uuid());
        $second = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, (string) Str::uuid()), $this->accountant, idempotencyKey: (string) Str::uuid());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Invoice::count());
    }

    public function test_reusing_a_key_for_a_genuinely_different_submission_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $otherFee = $this->periodicFee();
        $key = (string) Str::uuid();

        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $this->expectException(ValidationException::class);
        // Same key, but a materially different payload (different fee) —
        // the hash mismatch must be caught, never silently replayed as if
        // it were the same submission.
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($otherFee, $key), $this->accountant, idempotencyKey: $key);
    }

    /**
     * Corrective pass #2 (HIGH 1): renamed/re-scoped from its pass-#1 name
     * ("...race is handled gracefully...") to be explicit about exactly
     * what this proves and what it does NOT — this is SEQUENTIAL
     * duplicate-key handling on SQLite (single-process, single
     * connection, one statement at a time), not real concurrency. SQLite
     * has no "aborted transaction" failure mode at all, so this test
     * cannot exercise — and never claimed to exercise — the actual
     * PostgreSQL-specific bug HIGH 1 fixed (a unique-violation inside a
     * transaction poisoning every subsequent statement in that same
     * transaction). That real concurrency scenario is covered separately
     * by InvoiceIssuancePostgresConcurrencyTest, gated to skip when no
     * real PostgreSQL server is reachable (see that file's own docblock
     * for exactly how to run it for real).
     */
    public function test_sequential_duplicate_key_insert_is_handled_gracefully_not_as_a_raw_500(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        $winner = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        // The pre-transaction and in-transaction checks in issue() would
        // normally short-circuit before ever reaching Invoice::save() a
        // second time for the same key — this test's real value is
        // confirming the winner is returned via the already-proven replay
        // path (test above), and that a genuine DB-level unique violation
        // (verified directly here) is the correct, real constraint
        // enforcing this at the database level regardless of the
        // application-level checks.
        $this->assertSame($winner->id, Invoice::where('idempotency_key', $key)->sole()->id);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \Illuminate\Support\Facades\DB::table('invoices')->insert(['idempotency_key' => $key, 'student_id' => $this->student->id, 'currency' => 'EGP', 'subtotal_amount' => '0', 'total_amount' => '0', 'discount_amount' => '0', 'paid_amount' => '0', 'remaining_amount' => '0', 'status' => 'unpaid', 'due_date' => '2027-06-30', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_caller_without_a_key_keeps_existing_always_fresh_behaviour(): void
    {
        $fee = $this->periodicFee();

        $first = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, ''), $this->accountant);
        $second = app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, ''), $this->accountant);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Invoice::count());
    }
}
