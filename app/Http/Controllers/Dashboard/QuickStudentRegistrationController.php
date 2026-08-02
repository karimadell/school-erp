<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuickStudentRegistrationRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\Stage;
use App\Services\Admissions\QuickStudentRegistrationService;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuickStudentRegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(): View
    {
        return view('dashboard.quick-registration.create', [
            'academicYears' => AcademicYear::where('is_active', true)->orderByDesc('start_date')->get(),
            'stages' => Stage::with('grades')->orderBy('order')->get(),
            'modes' => EnrollmentMode::where('is_active', true)->orderBy('id')->get(),
            'fees' => Fee::with('prices')->active()->orderBy('category')->orderBy('name_ru')->get(),
            'mealPlans' => MealPlan::active()->orderBy('name_ru')->get(),
            'cashAccounts' => CashAccount::orderBy('name')->get(),
        ]);
    }

    public function store(
        StoreQuickStudentRegistrationRequest $request,
        QuickStudentRegistrationService $service,
    ): RedirectResponse {
        $result = $service->register($request->validated(), $request->user());

        return redirect()->route('dashboard.quick-registration.summary', $result['invoice'])
            ->with('success', 'Ученик предварительно зарегистрирован, счёт создан в EGP.');
    }

    public function price(Request $request, InvoiceCalculationService $calculator): JsonResponse
    {
        $data = $request->validate([
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'item' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'transport_area' => ['nullable', 'string', 'max:150'],
        ]);
        $fee = Fee::findOrFail($data['fee_id']);
        $calculation = $calculator->calculate([[
            'fee_id' => $fee->id,
            'quantity' => $data['quantity'],
            'item' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['item'] ?? null) : null,
            'size' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['size'] ?? null) : null,
            'option_type' => $fee->category === Fee::CATEGORY_TRANSPORT ? 'area' : null,
            'option_value' => $fee->category === Fee::CATEGORY_TRANSPORT ? ($data['transport_area'] ?? null) : null,
        ]]);

        return response()->json([
            'unit_price' => $calculation['line_items'][0]['unit_price'],
            'amount' => $calculation['line_items'][0]['amount'],
            'currency' => InvoiceCalculationService::CURRENCY,
        ]);
    }

    public function summary(Invoice $invoice): View
    {
        $invoice->load(['student', 'academicYear', 'payments', 'fees', 'items', 'createdBy']);
        abort_unless($invoice->student?->status === 'pre_registered', 404);

        return view('dashboard.quick-registration.summary', compact('invoice'));
    }
}
