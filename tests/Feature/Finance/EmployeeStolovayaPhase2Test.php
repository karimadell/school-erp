<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PayrollAdjustment;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\StaffFoodPurchase;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\RevenueService;
use Database\Seeders\RevenueCategorySeeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — an owner-approved,
 * dedicated point-of-sale cash flow for employee daily meals, entirely
 * separate from Student Stolovaya (Phase 1, closed) and from Buffet.
 * Reuses the existing Food FeePrice/MealPlan master data and the generic
 * RevenueEntry/RevenueService/CashTransaction accounting path, gated by
 * its own narrow 'manage employee stolovaya' permission — never the
 * generic 'manage revenues'/'post revenues' pair.
 */
class EmployeeStolovayaPhase2Test extends FinanceOperationsTestCase
{
    private MealPlan $mealPlan;

    private MealPlan $secondMealPlan;

    private Fee $food;

    private RevenueCategory $schoolFood;

    protected function setUp(): void
    {
        parent::setUp();
        (new RevenueCategorySeeder)->run();
        $this->schoolFood = RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->sole();

        // MealPlan.price is deliberately WRONG (999.00) — proof #10 below
        // asserts the charged amount comes from FeePrice, never this column.
        $this->mealPlan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => 'lunch', 'period' => 'daily', 'price' => '999.00', 'is_active' => true]);
        $this->secondMealPlan = MealPlan::create(['name_ru' => 'Напиток', 'meal_type' => 'both', 'period' => 'daily', 'price' => '999.00', 'is_active' => true]);

        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id,
            'amount' => '150.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->secondMealPlan->id,
            'amount' => '10.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    private function employee(string $role = 'teacher', bool $active = true): User
    {
        $employee = User::factory()->create(['is_active' => $active]);
        $employee->assignRole($role);

        return $employee;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'employee_user_id' => $this->employee()->id,
            'food_date' => '2026-09-01',
            'meal_plan_id' => $this->mealPlan->id,
            'quantity' => 1,
            'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function purchase(User $actor, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post(route('dashboard.employee-stolovaya.store'), $this->payload($overrides));
    }

    // 1 & 2. Dedicated permission required; the authorized cashier role
    // (already given 'manage employee stolovaya' by the seeder) can reach it.
    public function test_route_requires_dedicated_permission_and_cashier_is_authorized(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get(route('dashboard.employee-stolovaya.create'))->assertOk();

        $employee = $this->employee();
        $response = $this->purchase($cashier, ['employee_user_id' => $employee->id]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, StaffFoodPurchase::count());
    }

    // 3. Cashier does NOT gain generic manage revenues/post revenues.
    public function test_cashier_does_not_gain_generic_revenue_permissions(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $this->assertFalse($cashier->can('manage revenues'));
        $this->assertFalse($cashier->can('post revenues'));
        $this->assertTrue($cashier->can('manage employee stolovaya'));
    }

    // 33. RevenueEntry generic authorization remains intact — a cashier
    // (who now holds 'manage employee stolovaya') still cannot reach the
    // generic Revenue create form, which requires 'manage revenues'.
    public function test_cashier_still_cannot_reach_generic_revenue_create_form(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get(route('dashboard.finance.income.revenue.create'))->assertForbidden();
    }

    // 4. No "Assistant Director" role exists anywhere in this codebase
    // (confirmed during Phase 2 discovery) — documented here rather than
    // silently assumed. The closest available proxy for "a Finance-
    // adjacent but not cash-operating role" is 'reception', which the
    // seeder deliberately does NOT grant this permission to.
    public function test_reception_role_denied_as_assistant_director_proxy_no_such_role_exists(): void
    {
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->assertFalse($reception->can('manage employee stolovaya'));
        $this->actingAs($reception)->get(route('dashboard.employee-stolovaya.create'))->assertForbidden();
    }

    // 5. Teacher denied (zero admin-panel permissions of any kind). A bare
    // 'teacher' role with no linked Teacher-portal record is denied even
    // more strongly than a plain 403 — the pre-existing, unrelated
    // EnsureAdministrativePortalAccess middleware (which runs on every
    // dashboard route, not something this feature added) logs them out
    // and redirects to login before the permission check is ever reached.
    public function test_teacher_denied(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');

        $this->assertFalse($teacher->can('manage employee stolovaya'));
        $this->actingAs($teacher)->get(route('dashboard.employee-stolovaya.create'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    // 6. Only active, role-bearing Users are offered as eligible employees.
    public function test_employee_picker_only_offers_active_role_bearing_users(): void
    {
        $eligible = $this->employee();
        $inactive = $this->employee(active: false);
        $noRole = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.employee-stolovaya.create'));

        $response->assertSee($eligible->name, false);
        $response->assertDontSee($inactive->name, false);
        $response->assertDontSee($noRole->name, false);
    }

    // 7. Inactive User rejected server-side (not just excluded from the
    // picker — a crafted/tampered employee_user_id must also fail).
    public function test_inactive_employee_rejected_server_side(): void
    {
        $inactive = $this->employee(active: false);

        $response = $this->purchase($this->accountant, ['employee_user_id' => $inactive->id]);

        $response->assertSessionHasErrors('employee_user_id');
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 8. User without any role rejected server-side.
    public function test_employee_without_role_rejected_server_side(): void
    {
        $noRole = User::factory()->create(['is_active' => true]);

        $response = $this->purchase($this->accountant, ['employee_user_id' => $noRole->id]);

        $response->assertSessionHasErrors('employee_user_id');
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 9. Six sellable canonical MealPlans are offered (using this test's
    // own two + four more, matching the real canonical set's shape).
    public function test_six_canonical_meal_plans_are_sellable(): void
    {
        foreach (['Комплексное питание', 'Завтрак', 'Суп', 'Второе блюдо'] as $name) {
            $plan = MealPlan::create(['name_ru' => $name, 'meal_type' => 'both', 'period' => 'daily', 'price' => '999.00', 'is_active' => true]);
            FeePrice::create([
                'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
                'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $plan->id,
                'amount' => '50.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            ]);
        }

        $this->assertSame(6, MealPlan::sellableFood()->count());
    }

    // 10. Server uses FeePrice, not MealPlan.price or a client-supplied
    // amount — MealPlan.price is 999.00 above; the charged amount must be
    // FeePrice's 150.00, and any client-supplied amount/total is ignored
    // (the request shape has no such field at all).
    public function test_server_uses_fee_price_not_meal_plan_price(): void
    {
        $this->purchase($this->accountant, ['quantity' => 1])->assertSessionHasNoErrors();

        $purchase = StaffFoodPurchase::sole();
        $this->assertSame('150.00', (string) $purchase->unit_price);
        $this->assertSame('150.00', (string) $purchase->total_amount);
    }

    // 11 & 12. Quantity 1 and quantity >1 calculations.
    public function test_quantity_one_and_greater_than_one_calculations(): void
    {
        $this->purchase($this->accountant, ['meal_plan_id' => $this->secondMealPlan->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $first = StaffFoodPurchase::latest('id')->first();
        $this->assertSame('10.00', (string) $first->total_amount);

        $this->purchase($this->accountant, ['meal_plan_id' => $this->secondMealPlan->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $second = StaffFoodPurchase::latest('id')->first();
        $this->assertSame('20.00', (string) $second->total_amount);
        $this->assertSame(2, $second->quantity);
    }

    // 13, 14, 15. Invalid quantity rejected: zero, negative, > 20.
    public function test_invalid_quantity_rejected(): void
    {
        foreach ([0, -1, 21] as $quantity) {
            $response = $this->purchase($this->accountant, ['quantity' => $quantity]);
            $response->assertSessionHasErrors('quantity');
        }
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 16. Inactive/invalid MealPlan rejected.
    public function test_inactive_meal_plan_rejected(): void
    {
        $inactive = MealPlan::create(['name_ru' => 'Устаревшее', 'meal_type' => 'lunch', 'period' => 'daily', 'price' => '10.00', 'is_active' => false]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $inactive->id,
            'amount' => '10.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->purchase($this->accountant, ['meal_plan_id' => $inactive->id]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 17. Missing/out-of-range FeePrice rejected (date outside the
    // FeePrice's own validity window).
    public function test_out_of_window_fee_price_rejected(): void
    {
        $response = $this->purchase($this->accountant, ['food_date' => '2028-01-01']);

        $response->assertSessionHasErrors();
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 18, 19, 20. RevenueEntry category is always school_food, and cannot
    // be switched to buffet/cafeteria even by a crafted extra field (the
    // request shape never exposes revenue_category_id at all).
    public function test_revenue_entry_category_is_always_school_food_and_cannot_be_tampered(): void
    {
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();
        $cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);

        $this->purchase($this->accountant, ['revenue_category_id' => $buffet->id])->assertSessionHasNoErrors();

        $entry = RevenueEntry::sole();
        $this->assertSame($this->schoolFood->id, $entry->revenue_category_id);
        $this->assertNotSame($buffet->id, $entry->revenue_category_id);
        $this->assertNotSame($cafeteria->id, $entry->revenue_category_id);
    }

    // 21, 22, 23. Exactly one RevenueEntry and one CashTransaction, with
    // the correct amount/account/direction.
    public function test_purchase_creates_exactly_one_revenue_entry_and_one_correct_cash_transaction(): void
    {
        $this->purchase($this->accountant, ['quantity' => 2])->assertSessionHasNoErrors();

        $this->assertSame(1, RevenueEntry::count());
        $entry = RevenueEntry::sole();
        $this->assertTrue($entry->isPosted());
        $this->assertSame('300.00', (string) $entry->amount);

        $transactions = CashTransaction::query()->where('revenue_entry_id', $entry->id)->get();
        $this->assertCount(1, $transactions);
        $transaction = $transactions->first();
        $this->assertSame('300.00', (string) $transaction->amount);
        $this->assertSame($this->cash->id, $transaction->cash_account_id);
        $this->assertSame(CashTransaction::TYPE_IN, $transaction->type);
        $this->assertSame(CashTransaction::METHOD_CASH, $transaction->payment_method);
    }

    // 24. StaffFoodPurchase snapshots every required structured field.
    public function test_staff_food_purchase_snapshots_all_required_fields(): void
    {
        $employee = $this->employee();
        $this->purchase($this->accountant, ['employee_user_id' => $employee->id, 'quantity' => 3])->assertSessionHasNoErrors();

        $purchase = StaffFoodPurchase::sole();
        $this->assertSame($employee->id, $purchase->employee_user_id);
        $this->assertSame($this->food->id, $purchase->fee_id);
        $this->assertSame($this->mealPlan->id, $purchase->meal_plan_id);
        $this->assertSame((string) $this->mealPlan->id, $purchase->option_value);
        $this->assertSame('2026-09-01', $purchase->food_date->toDateString());
        $this->assertSame(3, $purchase->quantity);
        $this->assertSame('150.00', (string) $purchase->unit_price);
        $this->assertSame('450.00', (string) $purchase->total_amount);
        $this->assertNotNull($purchase->fee_price_id);
        $this->assertNotNull($purchase->revenue_entry_id);
        $this->assertSame($this->accountant->id, $purchase->created_by);
    }

    // 25. Same idempotency key + same payload = safe replay, zero duplicates.
    public function test_same_key_and_payload_replays_safely_with_zero_duplicates(): void
    {
        // Fixed explicit employee — payload()'s own default calls
        // $this->employee() fresh per invocation, which would otherwise
        // make the two "identical" requests below differ on
        // employee_user_id alone.
        $employee = $this->employee();
        $key = (string) Str::uuid();
        $this->purchase($this->accountant, ['idempotency_key' => $key, 'employee_user_id' => $employee->id])->assertSessionHasNoErrors();
        $this->purchase($this->accountant, ['idempotency_key' => $key, 'employee_user_id' => $employee->id])->assertSessionHasNoErrors();

        $this->assertSame(1, StaffFoodPurchase::count());
        $this->assertSame(1, RevenueEntry::count());
        $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', RevenueEntry::sole()->id)->count());
    }

    // 26. Same key + changed payload = rejected, no partial writes.
    public function test_same_key_with_changed_payload_is_rejected(): void
    {
        // Fixed explicit employee so quantity is the ONLY thing that
        // differs between the two requests below.
        $employee = $this->employee();
        $key = (string) Str::uuid();
        $this->purchase($this->accountant, ['idempotency_key' => $key, 'employee_user_id' => $employee->id, 'quantity' => 1])->assertSessionHasNoErrors();

        $response = $this->purchase($this->accountant, ['idempotency_key' => $key, 'employee_user_id' => $employee->id, 'quantity' => 2]);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, StaffFoodPurchase::count());
        $this->assertSame(1, RevenueEntry::count());
    }

    // 27. New key + identical purchase = legitimate second purchase.
    public function test_new_key_with_identical_purchase_is_allowed(): void
    {
        $employee = $this->employee();
        $this->purchase($this->accountant, ['employee_user_id' => $employee->id])->assertSessionHasNoErrors();
        $this->purchase($this->accountant, ['employee_user_id' => $employee->id])->assertSessionHasNoErrors();

        $this->assertSame(2, StaffFoodPurchase::count());
        $this->assertSame(2, RevenueEntry::count());
    }

    // 28. Missing idempotency key rejected.
    public function test_missing_idempotency_key_rejected(): void
    {
        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.employee-stolovaya.store'),
            collect($this->payload())->except('idempotency_key')->all(),
        );

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, StaffFoodPurchase::count());
    }

    // 29. Transaction rollback leaves no orphan RevenueEntry/CashTransaction
    // if StaffFoodPurchase (or anything downstream) fails — forces the
    // same simulated ledger failure RevenueAtomicCreationTest already uses.
    public function test_forced_failure_leaves_no_orphan_accounting_records(): void
    {
        $this->app->bind(CashSessionService::class, function () {
            return new class extends CashSessionService
            {
                public function __construct() {}

                public function activeFor(\App\Models\CashAccount $account, bool $lock = false): ?\App\Models\CashSession
                {
                    throw new RuntimeException('Simulated unexpected ledger-posting failure.');
                }
            };
        });

        // The controller only catches ValidationException — this
        // RuntimeException propagates to Laravel's own exception handler,
        // which renders a 500 response rather than re-throwing to the
        // test (unlike a direct service call). Either way, no partial
        // write may survive.
        $response = $this->purchase($this->accountant);
        $this->app->bind(CashSessionService::class, fn () => new CashSessionService());

        $response->assertStatus(500);
        $this->assertSame(0, RevenueEntry::count(), 'No RevenueEntry was left behind.');
        $this->assertSame(0, CashTransaction::count(), 'No CashTransaction was left behind.');
        $this->assertSame(0, StaffFoodPurchase::count(), 'No StaffFoodPurchase was left behind.');
    }

    // 30. Reversing the Phase 2 activation (school_food back to inactive)
    // makes RevenueService::postToLedger() reject the purchase — proving
    // activation is a real precondition, not decorative.
    public function test_inactive_school_food_category_blocks_posting(): void
    {
        $this->schoolFood->update(['is_active' => false]);

        $response = $this->purchase($this->accountant);

        // RevenueService::postToLedger() throws a ValidationException
        // ("категория дохода неактивна"), which the controller catches
        // and renders as a normal redirect-back-with-errors — never a 500.
        $response->assertSessionHasErrors();
        $this->assertSame(0, StaffFoodPurchase::count());
        // The whole persist() transaction (draft creation + posting
        // attempt) rolls back together — not just "no posted row left
        // behind", no RevenueEntry at all.
        $this->assertSame(0, RevenueEntry::count());
    }

    // 34. Payroll tables remain completely untouched by any Employee
    // Stolovaya purchase.
    public function test_payroll_tables_remain_untouched(): void
    {
        $this->purchase($this->accountant, ['quantity' => 2])->assertSessionHasNoErrors();

        $this->assertSame(0, TeacherSalary::count());
        $this->assertSame(0, PayrollAdjustment::count());
    }

    // RevenueService::createTrusted() must never be reachable from
    // anywhere except EmployeeFoodPurchaseService — architectural
    // safeguard mirroring RevenueAtomicCreationTest's own
    // test_no_application_code_bypasses_the_revenue_service_for_creation.
    public function test_create_trusted_has_no_other_caller_than_employee_food_purchase_service(): void
    {
        $offendingFiles = [];

        foreach ((new \Symfony\Component\Finder\Finder())->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRealPath();
            if (str_ends_with($path, 'RevenueService.php') || str_ends_with($path, 'EmployeeFoodPurchaseService.php')) {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            if (preg_match('/->createTrusted\s*\(/', $code) || preg_match('/::createTrusted\s*\(/', $code)) {
                $offendingFiles[] = str_replace(app_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offendingFiles, 'Found application code calling RevenueService::createTrusted() outside EmployeeFoodPurchaseService: '.implode(', ', $offendingFiles));
    }
}
