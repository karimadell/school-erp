<?php

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\DuplicateOpenInvoiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStolovayaChargeRequest;
use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\MealPlan;
use App\Models\Student;
use App\Services\Finance\ChargeAndCollectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Столовая (Student, Phase 1) — a dedicated daily-meal operational entry
 * point over the EXISTING, already-proven Food accounting path
 * (ChargeAndCollectService, the same engine chargeCreate()/chargeStore()
 * already use). This controller never resolves a price, issues an
 * invoice/coverage, or posts a payment itself — it only narrows the
 * general Charge & Collect screen down to "one student, one canonical
 * Food meal, one day" and hands off to the same service. Reached only
 * from the Приход → Столовая card (IncomeEntryController::stolovaya())
 * followed by the existing student search screen
 * (dashboard.finance.income.students) — no new search UI.
 *
 * Employee/staff meals are explicitly out of scope for this phase — see
 * the class docblock note in StoreStolovayaChargeRequest; this controller
 * only ever charges a Student.
 */
class StolovayaController extends Controller
{
    public function __construct()
    {
        // Same gate as FinanceOperationsController::chargeCreate()/chargeStore()
        // — Столовая is not a separately-permissioned surface, it is a
        // narrower entry point into the same money-charging domain.
        $this->middleware('permission:manage invoices');
    }

    public function create(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear']);
        $year = $student->currentEnrollment?->academicYear;
        $foodFee = Fee::active()->where('category', Fee::CATEGORY_FOOD)->first();

        return view('dashboard.finance.stolovaya.create', [
            'student' => $student,
            'year' => $year,
            'foodFee' => $foodFee,
            'mealPlans' => MealPlan::sellableFood(),
            'cashAccounts' => CashAccount::where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreStolovayaChargeRequest $request, Student $student, ChargeAndCollectService $service): RedirectResponse
    {
        try {
            $result = $service->chargeAndCollect(
                $student,
                $request->validated(),
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DuplicateOpenInvoiceException $exception) {
            return back()
                ->withInput()
                ->withErrors(['fee_id' => $exception->getMessage()])
                ->with('existing_invoice_id', $exception->invoiceId);
        }

        if ($result['payment']) {
            return redirect()->route('dashboard.payments.receipt', $result['payment'])
                ->with('success', 'Питание оформлено, оплата принята.');
        }

        return redirect()->route('dashboard.invoices.show', $result['invoice'])
            ->with('success', 'Питание оформлено без оплаты.');
    }
}
