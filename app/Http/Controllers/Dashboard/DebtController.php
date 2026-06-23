<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Invoice;
use App\Models\CashTransaction;
use Barryvdh\DomPDF\Facade\Pdf;

class DebtController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::with([
            'class',
            'invoices' => function ($q) {
                $q->where('status', 'unpaid');
            }
        ]);

        // ===== Filter by class =====
        if ($request->class_id) {
            $query->where('class_id', $request->class_id);
        }

        // ===== Filter by student (MULTI LANGUAGE SEARCH) =====
        if ($request->student_name) {
            $query->where(function($q) use ($request){
                $q->where('name','like','%'.$request->student_name.'%')
                  ->orWhere('name_ar','like','%'.$request->student_name.'%')
                  ->orWhere('name_ru','like','%'.$request->student_name.'%');
            });
        }

        $students = $query->get()->map(function ($student) {

            // حساب إجمالي الدين
            $student->total_debt = $student->invoices->sum('amount');

            return $student;
        })
        ->filter(function ($student) {
            return $student->total_debt > 0;
        });

        return view('dashboard.debts.index', compact('students'));
    }

    public function show($id)
    {
        $student = Student::with([
            'class',
            'invoices'
        ])->findOrFail($id);

        return view('dashboard.debts.show', compact('student'));
    }

    public function pay(Request $request, $invoiceId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01'
        ]);

        $invoice = Invoice::findOrFail($invoiceId);
        $paid = $request->amount;

        // ================= تسجيل في الخزنة =================
        CashTransaction::create([
            'cash_account_id' => 1, // مؤقت (هنخليه ديناميك بعد كدا)
            'type' => 'in',
            'amount' => $paid,
            'notes' => 'Debt payment - Student ID: ' . $invoice->student_id
        ]);

        // ================= تحديث الفاتورة =================
        if ($paid >= $invoice->amount) {
            $invoice->update([
                'status' => 'paid',
                'amount' => 0
            ]);
        } else {
            $invoice->update([
                'amount' => $invoice->amount - $paid
            ]);
        }

        return back()->with('success', 'Payment recorded + added to cash');
    }

    public function receipt($invoiceId)
    {
        $invoice = \App\Models\Invoice::with('student')->findOrFail($invoiceId);

        $data = [
            'student' => $invoice->student->name,
            'amount' => $invoice->amount,
            'date' => now()->format('Y-m-d'),
            'invoice_id' => $invoice->id,
        ];

        $pdf = Pdf::loadView('dashboard.debts.receipt', $data);

        return $pdf->download('receipt.pdf');
    }
}