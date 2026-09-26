<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\RevenueCategory;
use App\Models\StaffFoodPurchase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — the single orchestrator
 * for one employee's daily-meal cash purchase. Deliberately narrow and
 * point-of-sale-shaped: no Invoice, no InvoiceItem, no ServiceCoverage, no
 * Student Food machinery, and no employee debt of any kind — every
 * purchase is a single, immediate, fully-paid cash sale.
 *
 * Authorization boundary: this service (not RevenueService) is where
 * 'manage employee stolovaya' is checked. It then calls
 * RevenueService::createTrusted() — a trusted entry point that
 * deliberately skips RevenueService's own generic 'manage revenues'/'post
 * revenues' check, because THIS permission is the one already checked
 * here. See RevenueService::createTrusted()'s own docblock for the full
 * reasoning; this is the ONLY caller of that method.
 *
 * Pricing authority: FeePrice, resolved through
 * InvoiceCalculationService::resolveCoverageBasisPrice() — the exact same
 * public method the Food overlap-guard/coverage-basis machinery already
 * uses for Student Food. Never MealPlan.price, never a client-supplied
 * amount, never FoodBillableDayCalculator (that is a coverage-*range*
 * concept for Student subscriptions; an Employee purchase is a single
 * date with a quantity multiplier, not a billable-day count).
 *
 * Duplicate purchases: deliberately NOT guarded — multiple identical or
 * different purchases by the same employee on the same date are
 * legitimate point-of-sale transactions (see the Phase 2 design
 * decision), unlike Student Food's ServiceCoverage overlap rule, which
 * exists for a different reason (one coverage period per subscription)
 * that does not apply here. Only an ACCIDENTAL replay of the exact same
 * client submission (same idempotency_key) is suppressed — see
 * purchase()'s own handling below.
 *
 * Atomicity: one outer DB::transaction() spans FeePrice resolution
 * re-validation, RevenueService::createTrusted() (which opens its own
 * nested transaction/savepoint — the same pattern
 * ChargeAndCollectService already uses around InvoiceIssuanceService),
 * and the StaffFoodPurchase write. A failure anywhere rolls back
 * everything: no orphan RevenueEntry/CashTransaction can ever exist
 * without its StaffFoodPurchase, and vice versa.
 */
class EmployeeFoodPurchaseService
{
    private const MAX_QUANTITY = 20;

    public function __construct(
        private InvoiceCalculationService $calculator,
        private RevenueService $revenues,
    ) {}

    /** @param array{employee_user_id:int, food_date:string, meal_plan_id:int, quantity:int, cash_account_id:int, idempotency_key:string} $data */
    public function purchase(array $data, User $actor): StaffFoodPurchase
    {
        abort_unless($actor->can('manage employee stolovaya'), 403);

        $employee = $this->resolveEligibleEmployee((int) $data['employee_user_id']);
        $mealPlan = $this->resolveSellableMealPlan((int) $data['meal_plan_id']);
        $quantity = $this->validateQuantity($data['quantity']);
        $foodFee = $this->resolveTheFoodFee();
        // Resolved once, read-only, before the idempotency check — reused
        // unchanged for both the replay hash and the actual write below,
        // never re-resolved a second time (no risk of the two
        // disagreeing).
        $feePrice = $this->resolveAuthoritativePrice($foodFee, $mealPlan, $data['food_date']);
        $totalAmount = bcmul((string) $feePrice->amount, (string) $quantity, 2);

        $hash = $this->canonicalHash($employee->id, $data['food_date'], $mealPlan->id, $quantity, $foodFee->id, $feePrice->id, (int) $data['cash_account_id']);

        $existing = StaffFoodPurchase::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        // Deliberately OUTSIDE the transaction below: if the
        // StaffFoodPurchase insert fails on a genuine idempotency_key
        // race, letting the QueryException propagate out of
        // DB::transaction()'s closure is what makes Laravel roll back
        // EVERYTHING committed inside it (the RevenueEntry + CashTransaction
        // this same request just created) — catching it INSIDE the closure
        // instead would let that transaction commit anyway, orphaning
        // them. See "TRANSACTION BOUNDARY" in the Phase 2 design record.
        try {
            return DB::transaction(function () use ($data, $employee, $mealPlan, $quantity, $foodFee, $feePrice, $totalAmount, $hash, $actor): StaffFoodPurchase {
                $category = RevenueCategory::query()->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->first();
                if (! $category) {
                    // Genuinely unexpected — the Phase 2 activation
                    // migration guarantees this row exists on any
                    // environment where this code can run. Fail closed
                    // rather than silently invent a category identity here.
                    throw ValidationException::withMessages(['meal_plan_id' => 'Категория дохода «Школьное питание» не найдена.']);
                }

                $account = CashAccount::query()->where('id', $data['cash_account_id'])->where('is_active', true)->excludingOwner()->first();
                if (! $account) {
                    throw ValidationException::withMessages(['cash_account_id' => 'Касса или счёт не найдены либо неактивны.']);
                }

                $revenueEntry = $this->revenues->createTrusted([
                    'revenue_category_id' => $category->id,
                    'amount' => $totalAmount,
                    'revenue_date' => $data['food_date'],
                    'cash_account_id' => $account->id,
                    'payment_method' => CashTransaction::METHOD_CASH,
                    'description' => "Столовая (сотрудник): {$employee->name} — {$mealPlan->name_ru} x{$quantity}",
                    'created_by' => $actor->id,
                ], $actor);

                return StaffFoodPurchase::create([
                    'revenue_entry_id' => $revenueEntry->id,
                    'employee_user_id' => $employee->id,
                    'fee_id' => $foodFee->id,
                    'fee_price_id' => $feePrice->id,
                    'meal_plan_id' => $mealPlan->id,
                    'option_value' => (string) $mealPlan->id,
                    'food_date' => $data['food_date'],
                    'quantity' => $quantity,
                    'unit_price' => $feePrice->amount,
                    'total_amount' => $totalAmount,
                    'idempotency_key' => $data['idempotency_key'],
                    'idempotency_hash' => $hash,
                    'created_by' => $actor->id,
                ]);
            });
        } catch (QueryException $exception) {
            // Concurrency backstop: a genuine race on the same
            // idempotency_key. The transaction above has already rolled
            // back in full (no orphan RevenueEntry/CashTransaction); the
            // winning concurrent request's row is now visible — re-read
            // and replay it exactly like the pre-check above.
            if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate entry')) {
                $winner = StaffFoodPurchase::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($winner) {
                    return $this->replay($winner, $hash);
                }
            }
            throw $exception;
        }
    }

    /**
     * Server-authoritative price preview (display-only on the client —
     * the real purchase() call above re-resolves this independently and
     * never trusts a client-supplied amount/total).
     */
    public function previewUnitPrice(int $mealPlanId, string $foodDate): FeePrice
    {
        $mealPlan = $this->resolveSellableMealPlan($mealPlanId);
        $foodFee = $this->resolveTheFoodFee();

        return $this->resolveAuthoritativePrice($foodFee, $mealPlan, $foodDate);
    }

    private function resolveEligibleEmployee(int $userId): User
    {
        // Same eligibility rule EmployeePayrollService::assertEmployee()
        // already established and Filament's own employee_user_id Select
        // already queries by (TeacherSalaryResource::form()) — the
        // project's one existing precedent for "is this User a payable
        // employee", reused unchanged rather than inventing a new rule.
        $employee = User::query()->where('id', $userId)->where('is_active', true)->whereHas('roles')->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_user_id' => 'Сотрудник не найден или неактивен.']);
        }

        return $employee;
    }

    private function resolveSellableMealPlan(int $mealPlanId): MealPlan
    {
        $mealPlan = MealPlan::sellableFood()->firstWhere('id', $mealPlanId);
        if (! $mealPlan) {
            throw ValidationException::withMessages(['meal_plan_id' => 'Выбранное питание недоступно.']);
        }

        return $mealPlan;
    }

    private function resolveTheFoodFee(): Fee
    {
        $candidates = Fee::active()->where('category', Fee::CATEGORY_FOOD)->get();
        if ($candidates->count() !== 1) {
            // Do not silently choose among ambiguous master data — see the
            // Phase 2 design decision. Zero active Food fees and more than
            // one both fail closed here.
            throw ValidationException::withMessages(['meal_plan_id' => 'Услуга «Питание» не настроена однозначно — обратитесь к администратору.']);
        }

        return $candidates->first();
    }

    private function resolveAuthoritativePrice(Fee $foodFee, MealPlan $mealPlan, string $foodDate): FeePrice
    {
        $year = AcademicYear::where('is_active', true)->first();
        if (! $year) {
            throw ValidationException::withMessages(['food_date' => 'Не найден активный учебный год.']);
        }

        $feePrice = $this->calculator->resolveCoverageBasisPrice(
            $foodFee,
            ['option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id, 'payment_period' => Fee::PERIOD_DAILY],
            $foodDate,
            $year->id,
            Fee::PERIOD_DAILY,
        );

        if (! $feePrice) {
            throw ValidationException::withMessages(['meal_plan_id' => 'Не удалось определить цену для выбранного питания на эту дату.']);
        }

        return $feePrice;
    }

    private function validateQuantity(mixed $quantity): int
    {
        $quantity = (int) $quantity;
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'Количество должно быть от 1 до '.self::MAX_QUANTITY.'.']);
        }

        return $quantity;
    }

    private function canonicalHash(int $employeeId, string $foodDate, int $mealPlanId, int $quantity, int $feeId, int $feePriceId, int $cashAccountId): string
    {
        return hash('sha256', json_encode([
            'employee_user_id' => $employeeId,
            'food_date' => $foodDate,
            'meal_plan_id' => $mealPlanId,
            'quantity' => $quantity,
            'fee_id' => $feeId,
            'fee_price_id' => $feePriceId,
            'cash_account_id' => $cashAccountId,
        ]));
    }

    private function replay(StaffFoodPurchase $existing, string $hash): StaffFoodPurchase
    {
        if (! hash_equals($existing->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другой покупки.']);
        }

        return $existing;
    }
}
