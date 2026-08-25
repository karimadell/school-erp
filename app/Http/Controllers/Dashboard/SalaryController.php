<?php

namespace App\Http\Controllers\Dashboard;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\TeacherSalary;
use App\Services\Finance\EmployeePayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Compatibility bridge for the obsolete, disconnected /salaries workflow.
 * Payroll creation and payment now live exclusively in the canonical
 * employee payroll resource and EmployeePayrollService — index() is a
 * read-only dashboard-native mirror of that resource's list, and
 * approve()/pay() are thin bridges that call EmployeePayrollService
 * directly (same call the Filament table action makes) purely so the
 * user isn't bounced into the Filament panel for the two most-used
 * actions. Neither method contains any state-transition or cash-posting
 * logic of its own — EmployeePayrollService remains the sole mutation
 * path. Create/Edit stay Filament-backed: replicating their reactive
 * adjustments-repeater + live net-salary preview here would mean
 * duplicating real UI/calculation work for two low-frequency actions,
 * which is out of proportion to the benefit.
 */
class SalaryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view payroll')->only(['index', 'create', 'payslip']);
        $this->middleware('permission:manage payroll')->only(['store', 'import']);
        $this->middleware('permission:approve payroll')->only(['approve']);
        $this->middleware('permission:pay payroll')->only(['showPayForm', 'pay']);
    }

    public function index(): View
    {
        $salaries = TeacherSalary::query()
            ->with(['employee', 'teacher', 'adjustments'])
            ->orderByDesc('salary_month')
            ->orderByDesc('id')
            ->paginate(15);

        return view('dashboard.salaries.index', compact('salaries'));
    }

    public function create(): RedirectResponse
    {
        abort_unless(auth()->user()?->can('manage payroll'), 403);

        return redirect(TeacherSalaryResource::getUrl('create'));
    }

    public function store(): never
    {
        abort(410, __('teacher_salary.validation.legacy_disabled'));
    }

    public function approve(TeacherSalary $teacherSalary, EmployeePayrollService $payroll): RedirectResponse
    {
        $payroll->approve($teacherSalary, auth()->user());

        return redirect()->route('dashboard.salaries.index')->with('success', __('teacher_salary.approved'));
    }

    public function showPayForm(TeacherSalary $teacherSalary): View
    {
        $cashAccounts = CashAccount::query()->where('is_active', true)->orderBy('name')->get();

        return view('dashboard.salaries.pay', ['salary' => $teacherSalary, 'cashAccounts' => $cashAccounts]);
    }

    public function pay(Request $request, TeacherSalary $teacherSalary, EmployeePayrollService $payroll): RedirectResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'payment_method' => ['required', 'in:cash,card,bank,transfer'],
        ]);

        $payroll->pay($teacherSalary, CashAccount::findOrFail($data['cash_account_id']), $data['payment_method'], auth()->user());

        return redirect()->route('dashboard.salaries.index')->with('success', __('teacher_salary.paid'));
    }

    public function payslip(): RedirectResponse
    {
        return redirect(TeacherSalaryResource::getUrl('index'));
    }

    public function import(): never
    {
        abort(410, __('teacher_salary.validation.legacy_disabled'));
    }
}
