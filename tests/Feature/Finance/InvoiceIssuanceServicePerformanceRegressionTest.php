<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Support\Facades\DB;

/**
 * Perf regression guard for the D->E->F query audit (504 investigation,
 * 2026-08-29): InvoiceIssuanceService::issue() used to reload Fee via an
 * individual findOrFail() twice per line item (once before pricing, once
 * after invoice creation) and to run one existing-subscription query and one
 * invoice_fee attach() query per line item too. Asserts query *shape*, not a
 * brittle exact count or wall-clock time — the number of line items in these
 * tests is deliberately >1 so an O(N) regression would fail them.
 */
class InvoiceIssuanceServicePerformanceRegressionTest extends FinanceOperationsTestCase
{
    private function secondFee(): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '500.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function multiLineData(array $overrides = []): array
    {
        $second = $this->secondFee();

        return array_replace([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01',
            'pricing_date' => '2026-09-01',
            'items' => [
                ['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $second->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
            ],
            'payment_type' => 'one_time',
        ], $overrides);
    }

    /**
     * 1-8 combined: a two-line invoice still produces exactly the expected
     * total/items/installments/subscriptions, and does so with a bounded
     * (not O(N)) number of Fee reloads, subscription lookups, and pivot
     * inserts.
     */
    public function test_multi_line_invoice_avoids_per_item_fee_and_subscription_queries(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $invoice = app(InvoiceIssuanceService::class)
            ->issue($this->student, $this->multiLineData(), $this->accountant, subscriptionResolver: function ($fee, $selection, $enrollment) {
                return $enrollment->serviceSubscriptions()->create([
                    'fee_id' => $fee->id, 'start_date' => '2026-09-01', 'status' => StudentServiceSubscription::STATUS_ACTIVE,
                ])->id;
            });

        // 1: same invoice total regardless of how many lines produced it.
        $this->assertSame('1700.00', $invoice->total_amount);
        // 2: same InvoiceItems — one row per submitted line, right amounts.
        $this->assertSame(2, InvoiceItem::count());
        $this->assertEqualsCanonicalizing(['1200.00', '500.00'], InvoiceItem::pluck('amount')->all());
        // 3: same installments — one-time payment still yields exactly one.
        $this->assertSame(1, $invoice->installments()->count());
        // 4: same subscriptions — one per line, both linked to their item.
        $this->assertSame(2, StudentServiceSubscription::count());
        $this->assertSame(2, InvoiceItem::whereNotNull('subscription_id')->count());
        // 7: no duplicate invoice/pivot records.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, DB::table('invoice_fee')->count());

        $fees = array_filter($queries, fn ($sql) => str_contains($sql, 'from "fees"') || str_contains($sql, 'from `fees`'));
        // Before the fix: 2 individual Fee reloads per line item (map() pass
        // + post-creation pass) on top of InvoiceCalculationService's own
        // batched fetch — 5 total for 2 items. After: 2 batched fetches
        // total (this service's own + the calculator's), regardless of item
        // count. Assert a bound well under the old per-item behaviour
        // rather than an exact number, so unrelated query-shape changes
        // don't make this brittle.
        $this->assertLessThanOrEqual(3, count($fees), 'Fee should be batch-loaded, not reloaded per line item: ' . implode("\n", $fees));

        $subscriptionLookups = array_filter($queries, fn ($sql) => str_contains($sql, 'student_service_subscriptions') && str_contains($sql, 'select'));
        $this->assertLessThanOrEqual(2, count($subscriptionLookups), 'Existing-subscription lookups should be batched once per invoice, not once per line: ' . implode("\n", $subscriptionLookups));

        $pivotInserts = array_filter($queries, fn ($sql) => str_contains($sql, 'insert into "invoice_fee"') || str_contains($sql, 'insert into `invoice_fee`'));
        $this->assertCount(1, $pivotInserts, 'invoice_fee pivot rows should be attached in one batched call for distinct fee_ids: ' . implode("\n", $pivotInserts));
    }

    /**
     * Safety-net parity check for the attach() batching fallback: a fee_id
     * repeated across two lines is already impossible today — invoice_fee
     * has a unique (invoice_id, fee_id) constraint, so even the original
     * one-attach()-per-line code fails on the second row. What this proves
     * is that the batching optimization did not change *how* it fails: the
     * duplicate-detection in issue() correctly routes repeated fee_ids to
     * the original per-line attach() path instead of silently collapsing
     * them into one row via a PHP array key collision on the batched path
     * (which would have failed differently — or worse, "succeeded" having
     * silently dropped a row instead of throwing).
     */
    public function test_a_repeated_fee_id_across_two_lines_fails_loudly_instead_of_silently_collapsing(): void
    {
        $data = [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01',
            'pricing_date' => '2026-09-01',
            'items' => [
                ['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null, 'quantity' => 1],
                ['fee_id' => $this->fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null, 'quantity' => 1],
            ],
            'payment_type' => 'one_time',
        ];

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        try {
            app(InvoiceIssuanceService::class)->issue($this->student, $data, $this->accountant);
        } finally {
            // 6: same rollback behaviour — the whole outer transaction (and
            // this service's own nested one) unwinds; nothing is left half
            // -written from either line.
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_items', 0);
        }
    }
}
