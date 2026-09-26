<?php

namespace Tests\Feature\Finance;

use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Services\Finance\RevenueService;
use Database\Seeders\RevenueCategorySeeder;

/**
 * Route hardening for RevenueEntryController's {revenueEntry}-bound
 * routes (show/attachment/receipt/post/reverse/destroy). Proves malformed,
 * non-numeric identifiers are rejected by the router itself (a clean 404)
 * before Eloquent's implicit route-model binding ever runs a `WHERE id = ?`
 * query — the exact class of failure a real PostgreSQL/Supabase UAT
 * environment surfaced as SQLSTATE[22P02] ("invalid input syntax for type
 * bigint") for the literal segment "index", once traced back to a
 * manually-mistyped URL (there is no code anywhere generating that URL —
 * the real, canonical index route has never had an "/index" suffix).
 *
 * Deliberately uses raw literal URL strings (not the route() helper) for
 * the malformed-identifier cases — route() can only ever generate a
 * correct, real URI for a given route name, so it structurally cannot
 * reproduce or prove-against this class of bug. The canonical/valid cases
 * below still use route() as usual, since those are meant to prove the
 * existing, correct contract is unchanged.
 */
class RevenueEntryRouteConstraintTest extends FinanceOperationsTestCase
{
    private RevenueCategory $donation;

    protected function setUp(): void
    {
        parent::setUp();
        (new RevenueCategorySeeder)->run();
        $this->donation = RevenueCategory::where('code', RevenueCategory::CODE_DONATION)->sole();
    }

    // 1. The exact wrong URL this whole investigation traced back to.
    public function test_literal_index_suffix_url_is_rejected_as_not_found_not_a_server_error(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get('/dashboard/finance/income/revenue/index');

        $response->assertNotFound();
    }

    // 2. Any other arbitrary malformed (non-numeric) identifier must be
    // rejected the same way — this is a general routing hardening, not a
    // one-off fix for the literal word "index".
    public function test_arbitrary_non_numeric_identifier_is_rejected_as_not_found(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get('/dashboard/finance/income/revenue/not-a-number');

        $response->assertNotFound();
    }

    // 2b. Same hardening applies to every other {revenueEntry}-bound
    // route in this group, not just show().
    public function test_non_numeric_identifier_rejected_on_every_revenue_entry_bound_route(): void
    {
        $this->actingAs($this->accountant)->get('/dashboard/finance/income/revenue/index/attachment')->assertNotFound();
        $this->actingAs($this->accountant)->get('/dashboard/finance/income/revenue/index/receipt')->assertNotFound();
        $this->actingAs($this->accountant)->post('/dashboard/finance/income/revenue/index/post')->assertNotFound();
        $this->actingAs($this->accountant)->post('/dashboard/finance/income/revenue/index/reverse')->assertNotFound();
        $this->actingAs($this->accountant)->delete('/dashboard/finance/income/revenue/index')->assertNotFound();
    }

    // 3A. Canonical index — unchanged behavior.
    public function test_canonical_index_route_still_returns_ok(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.index'));

        $response->assertOk();
    }

    // 3B. Create — unchanged behavior.
    public function test_create_route_still_returns_ok(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.create'));

        $response->assertOk();
    }

    // 3C. A real, valid numeric RevenueEntry ID still resolves normally
    // through the same {revenueEntry} route the malformed cases above
    // were rejected on.
    public function test_valid_numeric_show_route_still_resolves_normally(): void
    {
        $entry = app(RevenueService::class)->create([
            'revenue_category_id' => $this->donation->id,
            'amount' => '100.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $this->accountant);

        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.show', $entry));

        $response->assertOk();
    }
}
