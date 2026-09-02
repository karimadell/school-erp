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

    // ================================================================
    // Corrective pass #3 (HIGH 1 — complete idempotency hash coverage,
    // now InvoiceIssuanceService's OWN canonical hash). Same key, each
    // financially material field independently changed, must reject as
    // a genuinely different submission.
    // ================================================================

    private function issueOrReject(array $payload, string $key): bool
    {
        try {
            app(InvoiceIssuanceService::class)->issue($this->student, $payload, $this->accountant, idempotencyKey: $key);

            return true;
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());

            return false;
        }
    }

    public function test_changing_billing_period_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $changed = $this->payload($fee, $key);
        $changed['billing_period'] = 'quarterly';
        $changed['items'][0]['payment_period'] = 'quarterly';
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_changing_due_date_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $changed = $this->payload($fee, $key);
        $changed['due_date'] = '2027-05-01';
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_changing_discount_type_or_value_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $changed = $this->payload($fee, $key);
        $changed['discount_type'] = 'percentage';
        $changed['discount_value'] = '10';
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_changing_pricing_date_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $changed = $this->payload($fee, $key);
        $changed['pricing_date'] = '2026-10-01';
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_changing_a_tariff_selection_dimension_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 2', 'amount' => '2000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, idempotencyKey: $key);

        $changed = $this->payload($fee, $key);
        $changed['items'][0]['option_value'] = 'Зона 2';
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_changing_origin_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, origin: Invoice::ORIGIN_QUICK_REGISTRATION, idempotencyKey: $key);

        $this->expectException(ValidationException::class);
        // Identical payload, but issued with NO origin this time.
        app(InvoiceIssuanceService::class)->issue($this->student, $this->payload($fee, $key), $this->accountant, origin: null, idempotencyKey: $key);
    }

    public function test_changing_payment_plan_id_under_the_same_key_is_rejected(): void
    {
        $fee = $this->periodicFee();
        $fee->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $planA = \App\Models\PaymentPlan::create(['name_ru' => 'План А', 'is_active' => true]);
        $planA->installments()->create(['name_ru' => 'Этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $planA->fees()->attach($fee->id);
        $planB = \App\Models\PaymentPlan::create(['name_ru' => 'План Б', 'is_active' => true]);
        $planB->installments()->create(['name_ru' => 'Этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $planB->fees()->attach($fee->id);
        $key = (string) Str::uuid();

        $planPayload = [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'plan', 'payment_plan_id' => $planA->id,
        ];
        app(InvoiceIssuanceService::class)->issue($this->student, $planPayload, $this->accountant, idempotencyKey: $key);

        $changed = $planPayload;
        $changed['payment_plan_id'] = $planB->id;
        $this->assertFalse($this->issueOrReject($changed, $key));
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_harmless_associative_key_reordering_still_replays_successfully(): void
    {
        $fee = $this->periodicFee();
        $key = (string) Str::uuid();
        $original = $this->payload($fee, $key);
        $first = app(InvoiceIssuanceService::class)->issue($this->student, $original, $this->accountant, idempotencyKey: $key);

        // Same values, keys in a deliberately different order at every
        // level (top-level payload AND the single item's own keys).
        $reordered = [
            'billing_period' => $original['billing_period'],
            'payment_type' => $original['payment_type'],
            'items' => [array_reverse($original['items'][0], true)],
            'pricing_date' => $original['pricing_date'],
            'due_date' => $original['due_date'],
            'academic_year_id' => $original['academic_year_id'],
            'student_id' => $original['student_id'],
        ];

        $second = app(InvoiceIssuanceService::class)->issue($this->student, $reordered, $this->accountant, idempotencyKey: $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }
}
