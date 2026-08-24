<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TeacherSalary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\View\View;

class TeacherSalaryPrintController extends Controller
{
    public function show(TeacherSalary $teacherSalary): View
    {
        $this->authorize('view', $teacherSalary);
        $teacherSalary->load(['teacher', 'employee', 'adjustments', 'cashTransaction.account']);

        return view('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => false]);
    }

    public function pdf(TeacherSalary $teacherSalary)
    {
        $this->authorize('view', $teacherSalary);
        $teacherSalary->load(['teacher', 'employee', 'adjustments', 'cashTransaction.account']);

        return Pdf::loadView('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => true])
            ->setPaper('a4')
            ->download('employee-payroll-'.$teacherSalary->id.'.pdf');
    }
}
