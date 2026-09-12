<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Authorization + read-only-surface coverage for the Combined Finance
 * Reporting V1 pages. Reuses the project's existing Finance viewing
 * permissions ('view cash reports', 'view collections') rather than any
 * new permission — see FinanceReportsController's class docblock. No
 * route in this feature performs a mutation; that is asserted directly
 * against the route list, not just by omission of a happy-path test.
 */
class FinanceReportsControllerTest extends FinanceOperationsTestCase
{
    // 20 (auth). Roles holding 'view cash reports' can reach the
    // cash-movement/index/account-balances pages.
    public function test_roles_with_view_cash_reports_can_access_cash_pages(): void
    {
        foreach (['super-admin', 'admin', 'school-admin', 'principal'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertOk();
            $this->actingAs($user)->get(route('dashboard.finance.reports.cash-movement'))->assertOk();
            $this->actingAs($user)->get(route('dashboard.finance.reports.account-balances'))->assertOk();
        }
    }

    // 20 (auth, negative). Accountant/cashier hold 'view collections' but
    // not 'view cash reports' — the cash-only pages must stay forbidden.
    // index() itself is NOT forbidden (see the permission-isolation tests
    // below) — it degrades to collections-only content instead.
    public function test_roles_without_view_cash_reports_are_forbidden_from_cash_only_pages(): void
    {
        foreach (['accountant', 'cashier'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('dashboard.finance.reports.cash-movement'))->assertForbidden();
            $this->actingAs($user)->get(route('dashboard.finance.reports.account-balances'))->assertForbidden();
        }
    }

    // Permission isolation on the combined index() page — the core fix of
    // this corrective. A user must never receive metrics from a domain
    // they lack the permission for, and the underlying service call for
    // that domain must not even be made (verified via the view's own data,
    // not just by what the HTML happens to hide).
    public function test_index_permission_isolation_cash_only(): void
    {
        $this->seedOneCashAndOneCollectionTransaction();
        $user = $this->user('reception');
        $user->givePermissionTo('view cash reports');

        $response = $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertOk();

        $data = $response->original->getData();
        $this->assertIsArray($data['cash']);
        $this->assertNull($data['collections']);
        $response->assertSee('Приход (касса)');
        $response->assertDontSee('Поступления от учеников (нетто)');
    }

    public function test_index_permission_isolation_collections_only(): void
    {
        $this->seedOneCashAndOneCollectionTransaction();
        $user = $this->user('accountant');
        $this->assertFalse($user->can('view cash reports'));
        $this->assertTrue($user->can('view collections'));

        $response = $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertOk();

        $data = $response->original->getData();
        $this->assertNull($data['cash']);
        $this->assertIsArray($data['collections']);
        $response->assertDontSee('Приход (касса)');
        $response->assertSee('Поступления от учеников (нетто)');
    }

    public function test_index_permission_isolation_both_permissions(): void
    {
        $this->seedOneCashAndOneCollectionTransaction();
        $user = $this->user('super-admin');

        $response = $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertOk();

        $data = $response->original->getData();
        $this->assertIsArray($data['cash']);
        $this->assertIsArray($data['collections']);
        $response->assertSee('Приход (касса)');
        $response->assertSee('Поступления от учеников (нетто)');
    }

    public function test_index_permission_isolation_neither_permission_is_forbidden(): void
    {
        $user = $this->user('reception');
        $this->assertFalse($user->can('view cash reports'));
        $this->assertFalse($user->can('view collections'));

        $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertForbidden();
    }

    private function seedOneCashAndOneCollectionTransaction(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '300.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Unrelated other income',
        ]);
    }

    // 20 (auth). Roles holding 'view collections' can reach the
    // student-collections page — including accountant/cashier, who are
    // forbidden from the cash pages but explicitly allowed here.
    public function test_roles_with_view_collections_can_access_student_collections_page(): void
    {
        foreach (['super-admin', 'admin', 'school-admin', 'principal', 'accountant', 'cashier'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('dashboard.finance.reports.student-collections'))->assertOk();
        }
    }

    // 20 (auth, negative). A role with neither permission (e.g. reception)
    // is forbidden from every reporting page.
    public function test_role_without_either_permission_is_forbidden_from_all_reporting_pages(): void
    {
        $user = $this->user('reception');

        $this->actingAs($user)->get(route('dashboard.finance.reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.finance.reports.cash-movement'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.finance.reports.account-balances'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.finance.reports.student-collections'))->assertForbidden();
    }

    // Malformed 'from'/'to' must never reach Carbon::parse() uncaught — a
    // normal Laravel validation redirect, never a 500.
    public function test_malformed_from_date_fails_validation_not_500(): void
    {
        $admin = $this->user('admin');

        foreach ([
            'dashboard.finance.reports.index',
            'dashboard.finance.reports.cash-movement',
            'dashboard.finance.reports.student-collections',
            'dashboard.finance.reports.account-balances',
        ] as $route) {
            $response = $this->actingAs($admin)->get(route($route, ['from' => 'not-a-date']));
            $response->assertStatus(302);
            $response->assertSessionHasErrors('from');
        }
    }

    public function test_malformed_to_date_fails_validation_not_500(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', ['to' => 'also-not-a-date']));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('to');
    }

    public function test_from_greater_than_to_is_rejected(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', [
            'from' => '2026-03-10', 'to' => '2026-03-01',
        ]));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('to');
    }

    public function test_valid_date_range_still_works(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.finance.reports.cash-movement', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk();
    }

    public function test_omitted_dates_still_default_to_current_month(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement'));
        $response->assertOk();
        $this->assertSame(now()->startOfMonth()->toDateString(), $response->original->getData()['filters']['from']);
        $this->assertSame(now()->endOfMonth()->toDateString(), $response->original->getData()['filters']['to']);
    }

    // Filter whitelisting: unknown values fail validation cleanly rather
    // than reaching raw SQL or an empty-but-silent result.
    public function test_invalid_source_type_filter_fails_validation(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', ['source_type' => 'not_a_real_type']));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('source_type');
    }

    public function test_invalid_payment_method_filter_fails_validation(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', ['payment_method' => 'bitcoin']));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('payment_method');
    }

    public function test_invalid_cash_account_id_filter_fails_validation(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', ['cash_account_id' => 999999]));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('cash_account_id');
    }

    public function test_valid_filters_still_work(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('dashboard.finance.reports.cash-movement', [
            'cash_account_id' => $this->cash->id,
            'type' => CashTransaction::TYPE_IN,
            'payment_method' => CashTransaction::METHOD_CASH,
        ]))->assertOk();
    }

    // Guests are redirected to login, not given data.
    public function test_guest_is_redirected_from_every_reporting_page(): void
    {
        $this->get(route('dashboard.finance.reports.index'))->assertRedirect();
        $this->get(route('dashboard.finance.reports.cash-movement'))->assertRedirect();
        $this->get(route('dashboard.finance.reports.student-collections'))->assertRedirect();
        $this->get(route('dashboard.finance.reports.account-balances'))->assertRedirect();
    }

    // No mutation actions are reachable from reporting: every route in the
    // finance.reports.* group is GET-only, and the controller exposes no
    // store/update/destroy method at all.
    public function test_no_reporting_route_accepts_a_mutating_http_method(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'dashboard.finance.reports.'));

        $this->assertGreaterThanOrEqual(4, $routes->count());

        foreach ($routes as $route) {
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_controller_exposes_no_mutation_methods(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Dashboard\FinanceReportsController::class);
        $publicMethods = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->reject(fn (\ReflectionMethod $m) => $m->isConstructor())
            ->map(fn (\ReflectionMethod $m) => $m->getName());

        foreach (['store', 'update', 'destroy', 'delete', 'create', 'edit'] as $mutating) {
            $this->assertNotContains($mutating, $publicMethods);
        }
    }
}
