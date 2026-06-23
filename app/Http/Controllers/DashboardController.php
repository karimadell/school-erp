<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {

        $totalIncome = Invoice::where('status','paid')->sum('total_amount');

        $invoicesCount = Invoice::count();

        $studentsCount = Student::count();

        $transactionsCount = CashTransaction::count();

        $cashBalance = CashAccount::sum('balance');


        // Cash today
        $todayIn = CashTransaction::whereDate('created_at', today())
            ->where('type','in')
            ->sum('amount');

        $todayOut = CashTransaction::whereDate('created_at', today())
            ->where('type','out')
            ->sum('amount');


        // Latest invoices
        $latestInvoices = Invoice::with('student')
            ->latest()
            ->take(5)
            ->get();


        // Top students
        $topStudents = Invoice::select(
                'student_id',
                DB::raw('SUM(total_amount) as total')
            )
            ->groupBy('student_id')
            ->orderByDesc('total')
            ->with('student')
            ->take(5)
            ->get();


        // Recent activity
        $lastAudits = AuditLog::with('user')
            ->latest()
            ->take(10)
            ->get();


        return view('dashboard.index', compact(
            'totalIncome',
            'invoicesCount',
            'studentsCount',
            'transactionsCount',
            'cashBalance',
            'todayIn',
            'todayOut',
            'latestInvoices',
            'topStudents',
            'lastAudits'
        ));
    }
}