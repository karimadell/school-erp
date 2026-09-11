<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Payee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart of PayeeResource (Filament). Same
 * 'manage expenses' gate as the Filament resource (PayeePolicy). No
 * destructive delete — historical Expense rows may reference a payee, so
 * this only ever supports create/edit + an active/inactive toggle via
 * update, never a destroy action.
 */
class PayeeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage expenses');
    }

    public function index(): View
    {
        $payees = Payee::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->paginate(25);

        return view('dashboard.finance.payees.index', ['payees' => $payees]);
    }

    public function create(): View
    {
        return view('dashboard.finance.payees.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayee($request);

        Payee::create($data);

        return redirect()
            ->route('dashboard.finance.payees.index')
            ->with('success', __('expenses.payee_created_notification'));
    }

    public function edit(Payee $payee): View
    {
        return view('dashboard.finance.payees.edit', ['payee' => $payee]);
    }

    public function update(Request $request, Payee $payee): RedirectResponse
    {
        $data = $this->validatePayee($request);

        $payee->update($data);

        return redirect()
            ->route('dashboard.finance.payees.index')
            ->with('success', __('expenses.payee_updated_notification'));
    }

    /**
     * Finance Workspace UX corrective — inline "+ Новый контрагент"
     * creation from within the Expense form, mirroring
     * ExpenseCategoryController::quickStore(). Always active for the same
     * reason (immediate selectability); phone/notes stay optional exactly
     * as the full form allows.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $payee = Payee::create([...$data, 'is_active' => true]);

        return response()->json(['id' => $payee->id, 'name' => $payee->name]);
    }

    private function validatePayee(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
