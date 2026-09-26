<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeFoodPurchaseRequest;
use App\Models\CashAccount;
use App\Models\MealPlan;
use App\Models\StaffFoodPurchase;
use App\Models\User;
use App\Services\Finance\EmployeeFoodPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — a separate, sibling
 * controller to the closed, UAT-passed Student StolovayaController. No
 * Employee branching logic was added to that controller; this one exists
 * instead, precisely so the Student flow's files stay byte-for-byte
 * unchanged. Gated by the dedicated 'manage employee stolovaya'
 * permission, not 'manage invoices' (Student) or 'manage revenues'/'post
 * revenues' (generic Revenue) — see EmployeeFoodPurchaseService's own
 * docblock for why.
 *
 * This controller never resolves a price, never decides the RevenueEntry
 * category, and never posts to the ledger itself — every one of those
 * decisions is EmployeeFoodPurchaseService's alone.
 */
class EmployeeStolovayaController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage employee stolovaya');
    }

    public function create(): View
    {
        return view('dashboard.finance.stolovaya.employee.create', [
            'employees' => User::query()->where('is_active', true)->whereHas('roles')->orderBy('name')->get(),
            'mealPlans' => MealPlan::sellableFood(),
            'cashAccounts' => CashAccount::query()->where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreEmployeeFoodPurchaseRequest $request, EmployeeFoodPurchaseService $service): RedirectResponse
    {
        try {
            $purchase = $service->purchase($request->validated(), $request->user());
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('dashboard.employee-stolovaya.show', $purchase)
            ->with('success', 'Питание оформлено, оплата принята.');
    }

    public function show(StaffFoodPurchase $staffFoodPurchase): View
    {
        $staffFoodPurchase->load(['employee', 'mealPlan', 'revenueEntry', 'creator']);

        return view('dashboard.finance.stolovaya.employee.show', ['purchase' => $staffFoodPurchase]);
    }

    /**
     * Server-authoritative price preview — display-only. store() above
     * independently re-resolves the same FeePrice and never trusts a
     * client-supplied amount/total; this endpoint exists purely so the
     * create form can show a live unit price/total before submit.
     */
    public function price(Request $request, EmployeeFoodPurchaseService $service): JsonResponse
    {
        $data = $request->validate([
            'meal_plan_id' => ['required', 'integer', 'exists:meal_plans,id'],
            'food_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $feePrice = $service->previewUnitPrice((int) $data['meal_plan_id'], $data['food_date']);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $unitPrice = (string) $feePrice->amount;
        $total = bcmul($unitPrice, (string) $data['quantity'], 2);

        return response()->json(['unit_price' => $unitPrice, 'amount' => $total]);
    }
}
