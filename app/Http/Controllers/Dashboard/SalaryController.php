<?php

namespace App\Http\Controllers\Dashboard;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Http\Controllers\Controller;
use App\Models\TeacherSalary;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Compatibility bridge for the obsolete, disconnected /salaries workflow.
 * Payroll creation and payment now live exclusively in the canonical
 * employee payroll resource and EmployeePayrollService — index() is a
 * read-only dashboard-native mirror of that resource's list (so the main
 * navigation doesn't have to jump out of the unified dashboard shell to
 * view payroll), not a second entry point for writes.
 */
class SalaryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view payroll')->only(['index', 'create', 'payslip']);
        $this->middleware('permission:manage payroll')->only(['store', 'import']);
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

    public function payslip(): RedirectResponse
    {
        return redirect(TeacherSalaryResource::getUrl('index'));
    }

    public function import(): never
    {
        abort(410, __('teacher_salary.validation.legacy_disabled'));
    }
}
