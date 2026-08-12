<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;

class FeeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage fees');
    }

    public function index()
    {
        $fees = Fee::query()
            ->when(request('search'), function ($query) {
                $query->where(function ($q) {
                    $q->where('name_ru', 'like', '%' . request('search') . '%')
                        ->orWhere('category', 'like', '%' . request('search') . '%')
                        ->orWhere('payment_period', 'like', '%' . request('search') . '%');
                });
            })
            ->when(request('category'), function ($query) {
                $query->where('category', request('category'));
            })
            ->latest()
            ->paginate(15);

        return view('dashboard.fees.index', compact('fees'));
    }

    public function create()
    {
        $grades = Grade::ordered()->get();

        return view('dashboard.fees.create', compact('grades'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_ru' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'grade_id' => 'nullable|exists:grades,id',
        ]);

        Fee::create([
            'name_ar' => $request->name_ar,
            'name_en' => $request->name_en,
            'name_ru' => $request->name_ru,
            'category' => $request->category,
            'grade_id' => $request->grade_id,
            'payment_period' => $request->payment_period,
            'amount' => $request->amount,
            'description' => $request->description,
            'is_active' => $request->has('is_active') ? 1 : 0,
        ]);

        return redirect()
            ->route('dashboard.fees.index')
            ->with('success', 'تم إضافة الخدمة');
    }

    public function edit(Fee $fee)
    {
        $grades = Grade::ordered()->get();

        return view('dashboard.fees.edit', compact('fee', 'grades'));
    }

    public function update(Request $request, Fee $fee)
    {
        $request->validate([
            'name_ru' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'grade_id' => 'nullable|exists:grades,id',
        ]);

        $fee->update([
            'name_ar' => $request->name_ar,
            'name_en' => $request->name_en,
            'name_ru' => $request->name_ru,
            'category' => $request->category,
            'grade_id' => $request->grade_id,
            'payment_period' => $request->payment_period,
            'amount' => $request->amount,
            'description' => $request->description,
            'is_active' => $request->has('is_active') ? 1 : 0,
        ]);

        return redirect()
            ->route('dashboard.fees.index')
            ->with('success', 'تم تعديل الخدمة');
    }

    public function destroy(Fee $fee)
    {
        $fee->delete();

        return back()->with('success', 'تم حذف الخدمة');
    }

    public function toggle(Fee $fee)
    {
        $fee->update([
            'is_active' => ! $fee->is_active,
        ]);

        return back()->with('success', 'تم تغيير حالة الخدمة بنجاح');
    }

    // Phase 0 safety lockdown: these methods created raw invoices (no invoice
    // number, no academic year, no items, no created_by, float pricing) outside
    // InvoiceIssuanceService, and were not wired to any route. Invoices for a
    // fee must be issued via canonical individual invoicing or Mass Billing.
    public function assignToStudent(Request $request)
    {
        abort(410, 'Устаревшее начисление услуги отключено. Используйте создание счёта или массовое начисление.');
    }

    public function bulkAssign(Request $request)
    {
        abort(410, 'Устаревшее массовое начисление услуги отключено. Используйте массовое начисление счетов.');
    }
}
