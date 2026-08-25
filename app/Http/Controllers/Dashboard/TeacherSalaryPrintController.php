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
        $teacherSalary->load(['teacher', 'employee', 'adjustments', 'cashTransaction.account', 'payer']);

        return view('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => false]);
    }

    public function pdf(TeacherSalary $teacherSalary)
    {
        $this->authorize('view', $teacherSalary);
        $teacherSalary->load(['teacher', 'employee', 'adjustments', 'cashTransaction.account', 'payer']);

        // A5 portrait: dompdf ships "a5" as a built-in paper size
        // (vendor/dompdf/dompdf/src/Adapter/CPDF.php), so this is exactly
        // as safe/standard as the "a4" it replaces — matches the
        // template's @page size:A5 portrait rule for the browser print
        // path.
        return Pdf::loadView('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => true])
            ->setPaper('a5', 'portrait')
            ->download('employee-payroll-'.$teacherSalary->id.'.pdf');
    }
}
