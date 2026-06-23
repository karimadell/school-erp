<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Salary;
use App\Models\Teacher;
use App\Models\CashTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SalaryImport;

class SalaryController extends Controller
{
    public function index()
    {
        $salaries = Salary::with('teacher')->latest()->get();
        return view('dashboard.salaries.index', compact('salaries'));
    }

    public function create()
    {
        $teachers = Teacher::all();
        return view('dashboard.salaries.create', compact('teachers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'base_salary' => 'required|numeric',
            'bonus' => 'nullable|numeric',
            'deduction' => 'nullable|numeric',
        ]);

        $net = $request->base_salary + ($request->bonus ?? 0) - ($request->deduction ?? 0);

        $salary = Salary::create([
            'teacher_id' => $request->teacher_id,
            'base_salary' => $request->base_salary,
            'bonus' => $request->bonus ?? 0,
            'deduction' => $request->deduction ?? 0,
            'net_salary' => $net,
            'month' => now(),
        ]);

        // 💰 تسجيل في الخزنة
        CashTransaction::create([
            'cash_account_id' => 1,
            'type' => 'out',
            'amount' => $net,
            'description' => 'Salary payment - Teacher ID: '.$salary->teacher_id
        ]);

        return redirect()->route('dashboard.salaries.index')
            ->with('success','Salary saved');
    }

    /*
    |--------------------------------------------------------------------------
    | 📄 PDF Payslip
    |--------------------------------------------------------------------------
    */

    public function payslip($id)
    {
        $salary = Salary::with('teacher')->findOrFail($id);

        $pdf = Pdf::loadView('dashboard.salaries.payslip', compact('salary'));

        return $pdf->download('salary_'.$salary->id.'.pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | 📥 Import Excel
    |--------------------------------------------------------------------------
    */

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv'
        ]);

        Excel::import(new SalaryImport, $request->file('file'));

        return back()->with('success','Salaries imported successfully');
    }
}