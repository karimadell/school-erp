<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportCardController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Restaurant Daily Summary
    |--------------------------------------------------------------------------
    */

    public function restaurant(Request $request)
    {
        $date = $request->date 
            ? Carbon::parse($request->date)->toDateString() 
            : now()->toDateString();

        $query = Invoice::whereDate('due_date', $date)
            ->where('service', 'restaurant');

        $paidCount = (clone $query)
            ->where('status', 'paid')
            ->count();

        $unpaidCount = (clone $query)
            ->where('status', 'unpaid')
            ->count();

        $students = $query
            ->with('student')
            ->latest()
            ->get();

        return view('dashboard.reports.restaurant', compact(
            'date',
            'paidCount',
            'unpaidCount',
            'students'
        ));
    }


    /*
    |--------------------------------------------------------------------------
    | Restaurant Daily (Kitchen List)
    |--------------------------------------------------------------------------
    */

    public function restaurantDaily(Request $request)
    {
        $date = $request->date 
            ? Carbon::parse($request->date)->toDateString() 
            : now()->toDateString();

        $students = Invoice::with(['student.class'])
            ->whereDate('due_date', $date)
            ->where('service', 'restaurant')
            ->where('status', 'paid')
            ->get();

        $totalStudents = $students->count();

        return view('dashboard.reports.restaurant_daily', compact(
            'students',
            'date',
            'totalStudents'
        ));
    }


    /*
    |--------------------------------------------------------------------------
    | Restaurant Weekly Report
    |--------------------------------------------------------------------------
    */

    public function restaurantWeekly(Request $request)
    {
        $start = $request->start 
            ? Carbon::parse($request->start)->toDateString()
            : now()->startOfWeek()->toDateString();

        $end = $request->end 
            ? Carbon::parse($request->end)->toDateString()
            : now()->endOfWeek()->toDateString();

        $data = Invoice::selectRaw("
                DATE(due_date) as day,
                COUNT(*) as total
            ")
            ->whereBetween('due_date', [$start, $end])
            ->where('service', 'restaurant')
            ->where('status', 'paid')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('dashboard.reports.restaurant_weekly', compact(
            'data',
            'start',
            'end'
        ));
    }


    /*
    |--------------------------------------------------------------------------
    | Restaurant Kitchen Report (Grouped by Class)
    |--------------------------------------------------------------------------
    */

    public function restaurantKitchen(Request $request)
    {
        $date = $request->date 
            ? Carbon::parse($request->date)->toDateString()
            : now()->toDateString();

        $data = Invoice::selectRaw("
                classes.name_ar as class_name,
                COUNT(invoices.id) as total
            ")
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->whereDate('invoices.due_date', $date)
            ->where('invoices.service', 'restaurant')
            ->where('invoices.status', 'paid')
            ->groupBy('classes.name_ar')
            ->orderBy('classes.name_ar')
            ->get();

        return view('dashboard.reports.restaurant_kitchen', compact(
            'date',
            'data'
        ));
    }

    public function restaurantKitchenPdf(Request $request)
    {
        $date = $request->date ?? now()->toDateString();

        $data = Invoice::selectRaw("
                classes.name_ar as class_name,
                COUNT(invoices.id) as total
            ")
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->whereDate('invoices.due_date', $date)
            ->where('invoices.service', 'restaurant')
            ->where('invoices.status', 'paid')
            ->groupBy('classes.name_ar')
            ->orderBy('classes.name_ar')
            ->get();

        $pdf = Pdf::loadView('dashboard.reports.restaurant_kitchen_pdf', compact('date', 'data'));

        return $pdf->download('kitchen_report.pdf');
    }

    public function restaurantDashboard()
    {
        $today = Invoice::whereDate('due_date', now())
            ->where('service','restaurant')
            ->where('status','paid')
            ->count();

        $week = Invoice::whereBetween('due_date', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ])
        ->where('service','restaurant')
        ->where('status','paid')
        ->count();

        return view('dashboard.reports.restaurant_dashboard', compact('today','week'));
    }
}