<?php

namespace App\Http\Controllers\Dashboard;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Compatibility bridge for the obsolete, disconnected /salaries workflow.
 * Payroll creation and payment now live exclusively in the canonical
 * employee payroll resource and EmployeePayrollService.
 */
class SalaryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view payroll')->only(['index', 'create', 'payslip']);
        $this->middleware('permission:manage payroll')->only(['store', 'import']);
    }

    public function index(): RedirectResponse
    {
        return redirect(TeacherSalaryResource::getUrl('index'));
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
