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
        $teacherSalary->load('teacher');

        return view('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => false]);
    }

    public function pdf(TeacherSalary $teacherSalary)
    {
        $teacherSalary->load('teacher');

        return Pdf::loadView('dashboard.teacher-salaries.print', ['salary' => $teacherSalary, 'pdf' => true])
            ->setPaper('a4')
            ->download('teacher-salary-'.$teacherSalary->id.'.pdf');
    }
}
