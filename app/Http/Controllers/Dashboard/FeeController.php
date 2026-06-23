<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FeeController extends Controller
{
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
        $grades = Schema::hasColumn('grades', 'name_ru')
            ? Grade::orderBy('name_ru')->get()
            : Grade::orderBy('name')->get();

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
        $grades = Schema::hasColumn('grades', 'name_ru')
            ? Grade::orderBy('name_ru')->get()
            : Grade::orderBy('name')->get();

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

    public function assignToStudent(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'fee_id' => 'required|exists:fees,id',
        ]);

        $student = Student::findOrFail($request->student_id);
        $fee = Fee::findOrFail($request->fee_id);

        $amount = method_exists($fee, 'currentPrice')
            ? $fee->currentPrice(now()->toDateString())
            : (float) ($fee->amount ?? $fee->base_price ?? 0);

        $invoice = Invoice::create([
            'student_id' => $student->id,
            'customer_name' => $student->name,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $invoice->fees()->attach($fee->id, [
            'amount' => $amount,
        ]);

        return back()->with('success', 'تم إنشاء فاتورة');
    }

    public function bulkAssign(Request $request)
    {
        $request->validate([
            'fee_id' => 'required|exists:fees,id',
        ]);

        $fee = Fee::findOrFail($request->fee_id);

        $students = Student::query()
            ->when($request->class_id, fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->students, fn ($q) => $q->whereIn('id', $request->students))
            ->get();

        $created = 0;

        foreach ($students as $student) {
            $amount = method_exists($fee, 'currentPrice')
                ? $fee->currentPrice(now()->toDateString())
                : (float) ($fee->amount ?? $fee->base_price ?? 0);

            $invoice = Invoice::create([
                'student_id' => $student->id,
                'customer_name' => $student->name,
                'total_amount' => $amount,
                'paid_amount' => 0,
                'remaining_amount' => $amount,
                'status' => Invoice::STATUS_UNPAID,
            ]);

            $invoice->fees()->attach($fee->id, [
                'amount' => $amount,
            ]);

            $created++;
        }

        return back()->with('success', "$created invoices created");
    }
}