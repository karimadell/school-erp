<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart of ExpenseCategoryResource (Filament). Same
 * 'manage expenses' gate as the Filament resource (ExpenseCategoryPolicy).
 * No destructive delete — historical Expense rows may reference a
 * category, so this only ever supports create/edit + an active/inactive
 * toggle via update, never a destroy action.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage expenses');
    }

    public function index(): View
    {
        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->paginate(25);

        return view('dashboard.finance.expense-categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        return view('dashboard.finance.expense-categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCategory($request);

        ExpenseCategory::create($data);

        return redirect()
            ->route('dashboard.finance.expense-categories.index')
            ->with('success', __('expenses.category_created_notification'));
    }

    public function edit(ExpenseCategory $expenseCategory): View
    {
        return view('dashboard.finance.expense-categories.edit', ['category' => $expenseCategory]);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $data = $this->validateCategory($request, $expenseCategory);

        $expenseCategory->update($data);

        return redirect()
            ->route('dashboard.finance.expense-categories.index')
            ->with('success', __('expenses.category_updated_notification'));
    }

    private function validateCategory(Request $request, ?ExpenseCategory $category = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('expense_categories', 'name')->ignore($category?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
