<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FeePriceController extends Controller
{
    public function index()
    {
        $prices = FeePrice::with(['fee', 'grade'])
            ->latest()
            ->paginate(20);

        return view('dashboard.fee-prices.index', compact('prices'));
    }

    public function create()
    {
        $fees = Fee::orderBy('name_ru')->get();

        $grades = Schema::hasColumn('grades', 'name_ru')
            ? Grade::orderBy('name_ru')->get()
            : Grade::orderBy('name')->get();

        return view('dashboard.fee-prices.create', compact('fees', 'grades'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fee_id' => ['required', 'exists:fees,id'],

            'grade_id' => ['nullable', 'exists:grades,id'],
            'payment_period' => ['nullable', 'string'],

            'amount' => ['required', 'numeric', 'min:0'],

            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'option_type' => ['nullable', 'string'],
            'option_value' => ['nullable', 'string'],

            'size' => ['nullable', 'string'],
            'item' => ['nullable', 'string'],

            'notes' => ['nullable', 'string'],

            'is_active' => ['nullable', 'boolean'],

            // 👇 الجديد
            'extra_hours' => ['nullable', 'string'],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Smart Mapping (IMPORTANT 🔥)
        |--------------------------------------------------------------------------
        */

        // لو Extra classes → نحولها لـ option_value
        if (!empty($data['extra_hours'])) {
            $data['option_type'] = 'hours';
            $data['option_value'] = $data['extra_hours'];
        }

        // لو Transport بدون option_type
        if (!empty($request->option_value) && empty($data['option_type'])) {
            $data['option_type'] = 'zone';
        }

        FeePrice::create([
            'fee_id' => $data['fee_id'],

            'grade_id' => $data['grade_id'] ?? null,
            'payment_period' => $data['payment_period'] ?? null,

            'amount' => $data['amount'],

            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,

            'option_type' => $data['option_type'] ?? null,
            'option_value' => $data['option_value'] ?? null,

            'size' => $data['size'] ?? null,
            'item' => $data['item'] ?? null,

            'notes' => $data['notes'] ?? null,

            'is_active' => $data['is_active'] ?? 1,
        ]);

        return redirect()
            ->route('dashboard.fee-prices.index')
            ->with('success', 'تم إضافة السعر بنجاح');
    }

    public function edit(FeePrice $feePrice)
    {
        $fees = Fee::orderBy('name_ru')->get();

        $grades = Schema::hasColumn('grades', 'name_ru')
            ? Grade::orderBy('name_ru')->get()
            : Grade::orderBy('name')->get();

        return view('dashboard.fee-prices.edit', compact('feePrice', 'fees', 'grades'));
    }

    public function update(Request $request, FeePrice $feePrice)
    {
        $data = $request->validate([
            'fee_id' => ['required', 'exists:fees,id'],

            'grade_id' => ['nullable', 'exists:grades,id'],
            'payment_period' => ['nullable', 'string'],

            'amount' => ['required', 'numeric', 'min:0'],

            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'option_type' => ['nullable', 'string'],
            'option_value' => ['nullable', 'string'],

            'size' => ['nullable', 'string'],
            'item' => ['nullable', 'string'],

            'notes' => ['nullable', 'string'],

            'is_active' => ['nullable', 'boolean'],

            // 👇 الجديد
            'extra_hours' => ['nullable', 'string'],
        ]);

        // نفس اللوجيك هنا
        if (!empty($data['extra_hours'])) {
            $data['option_type'] = 'hours';
            $data['option_value'] = $data['extra_hours'];
        }

        $feePrice->update($data);

        return redirect()
            ->route('dashboard.fee-prices.index')
            ->with('success', 'تم تعديل السعر');
    }

    public function destroy(FeePrice $feePrice)
    {
        $feePrice->delete();

        return back()->with('success', 'تم حذف السعر');
    }
}