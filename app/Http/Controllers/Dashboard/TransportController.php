<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TransportRoute;
use App\Models\TransportSubscription;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Invoice;
use App\Models\Fee;
use Carbon\Carbon;

class TransportController extends Controller
{
    public function index()
    {
        $routes = TransportRoute::withCount([
            'students',
            'students as active_students_count' => function ($q) {
                $q->where('status', 'active');
            }
        ])->latest()->get();

        return view('dashboard.transport.index', compact('routes'));
    }

    public function create()
    {
        return view('dashboard.transport.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'driver_name' => 'nullable|string',
            'bus_number' => 'nullable|string',
            'capacity' => 'required|integer|min:1'
        ]);

        TransportRoute::create($request->only([
            'name',
            'driver_name',
            'bus_number',
            'capacity'
        ]));

        return redirect()
            ->route('dashboard.transport.index')
            ->with('success', 'Route created successfully');
    }

    public function subscribeForm()
    {
        $students = Student::orderBy('name')->get();
        $routes = TransportRoute::orderBy('name')->get();

        return view('dashboard.transport.subscribe', compact('students', 'routes'));
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'route_id' => 'required|exists:transport_routes,id',
        ]);

        $exists = TransportSubscription::where('student_id', $request->student_id)
            ->where('status', 'active')
            ->exists();

        if ($exists) {
            return back()->with('error', 'Student already subscribed');
        }

        TransportSubscription::create([
            'student_id' => $request->student_id,
            'route_id' => $request->route_id,
            'status' => 'active'
        ]);

        $fee = Fee::where('category', 'transport')
            ->where('is_active', 1)
            ->first();

        if ($fee) {
            $invoiceExists = Invoice::where('student_id', $request->student_id)
                ->where('fee_id', $fee->id)
                ->whereMonth('due_date', now()->month)
                ->whereYear('due_date', now()->year)
                ->exists();

            if (! $invoiceExists) {
                Invoice::create([
                    'student_id' => $request->student_id,
                    'fee_id' => $fee->id,
                    'amount' => $fee->amount,
                    'service' => 'transport',
                    'status' => 'unpaid',
                    'due_date' => now(),
                ]);
            }
        }

        return back()->with('success', 'Student subscribed + invoice created');
    }

    public function subscriptions(Request $request)
    {
        $query = TransportSubscription::with(['student', 'route']);

        if ($request->route_id) {
            $query->where('route_id', $request->route_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $subscriptions = $query->latest()->get();
        $routes = TransportRoute::orderBy('name')->get();

        return view('dashboard.transport.subscriptions', compact('subscriptions', 'routes'));
    }

    public function moveForm($id)
    {
        $subscription = TransportSubscription::with(['student', 'route'])->findOrFail($id);
        $routes = TransportRoute::where('id', '!=', $subscription->route_id)->orderBy('name')->get();

        return view('dashboard.transport.move', compact('subscription', 'routes'));
    }

    public function move(Request $request, $id)
    {
        $request->validate([
            'route_id' => 'required|exists:transport_routes,id',
        ]);

        $subscription = TransportSubscription::findOrFail($id);

        $subscription->update([
            'route_id' => $request->route_id
        ]);

        return redirect()
            ->route('dashboard.transport.subscriptions')
            ->with('success', 'Student moved successfully');
    }

    public function stop($id)
    {
        $subscription = TransportSubscription::findOrFail($id);

        $subscription->update([
            'status' => 'stopped'
        ]);

        return back()->with('success', 'Subscription stopped');
    }

    public function report()
    {
        $routes = TransportRoute::with([
            'students' => function ($q) {
                $q->where('status', 'active');
            },
            'students.student'
        ])->get();

        return view('dashboard.transport.report', compact('routes'));
    }

    public function reportPdf()
    {
        $routes = TransportRoute::with([
            'students' => function ($q) {
                $q->where('status', 'active');
            },
            'students.student'
        ])->get();

        $pdf = Pdf::loadView('dashboard.transport.report_pdf', compact('routes'));

        return $pdf->download('transport_report.pdf');
    }

    public function monthlyInvoices()
    {
        $fee = Fee::where('category', 'transport')
            ->where('is_active', 1)
            ->first();

        if (! $fee) {
            return back()->with('error', 'No active transport fee found');
        }

        $subscriptions = TransportSubscription::where('status', 'active')->get();

        $created = 0;
        $month = Carbon::now()->month;
        $year = Carbon::now()->year;

        foreach ($subscriptions as $sub) {
            $exists = Invoice::where('student_id', $sub->student_id)
                ->where('fee_id', $fee->id)
                ->whereMonth('due_date', $month)
                ->whereYear('due_date', $year)
                ->exists();

            if ($exists) {
                continue;
            }

            Invoice::create([
                'student_id' => $sub->student_id,
                'fee_id' => $fee->id,
                'amount' => $fee->amount,
                'service' => 'transport',
                'status' => 'unpaid',
                'due_date' => now()->startOfMonth(),
            ]);

            $created++;
        }

        return back()->with('success', "$created monthly transport invoices created");
    }
}