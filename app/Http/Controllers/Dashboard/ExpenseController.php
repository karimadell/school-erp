<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payee;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart of App\Filament\Resources\Expenses\
 * ExpenseResource — same canonical workflow, permissions, and immutability
 * rules, reached from the unified operational shell instead of /admin.
 * Filament's Expense resource is untouched and remains a technical
 * fallback; this controller never bypasses ExpenseService for any
 * status-changing mutation (create/approve/pay/void all delegate to it —
 * see ExpenseAtomicCreationTest::test_no_application_code_bypasses_the_
 * expense_service_for_creation, which fails the build if any app/ code
 * calls Expense::create()/new Expense() outside ExpenseService).
 *
 * Authorization mirrors ExpensePolicy exactly via $this->authorize() calls
 * rather than a blanket permission middleware, since approve/pay/void each
 * carry their own narrower permission + status gate independent of
 * 'manage expenses' (see ExpensePolicy for the exact rules).
 */
class ExpenseController extends Controller
{
    private const ATTACHMENT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $filters = [
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'expense_category_id' => $request->integer('expense_category_id') ?: null,
            'payee_id' => $request->integer('payee_id') ?: null,
            'cash_account_id' => $request->integer('cash_account_id') ?: null,
            'payment_method' => $request->filled('payment_method') ? $request->string('payment_method')->toString() : null,
            'date_from' => $request->date('date_from'),
            'date_until' => $request->date('date_until'),
            'search' => $request->filled('search') ? $request->string('search')->toString() : null,
        ];

        $expenses = Expense::query()
            ->with(['expenseCategory', 'payee', 'cashAccount', 'creator'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['expense_category_id'], fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['payee_id'], fn ($q, $id) => $q->where('payee_id', $id))
            ->when($filters['cash_account_id'], fn ($q, $id) => $q->where('cash_account_id', $id))
            ->when($filters['payment_method'], fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('expense_date', '>=', $date))
            ->when($filters['date_until'], fn ($q, $date) => $q->whereDate('expense_date', '<=', $date))
            ->when($filters['search'], fn ($q, $search) => $q->where(
                fn ($sq) => $sq->where('reference_number', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")
            ))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.finance.expenses.index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'categories' => ExpenseCategory::query()->orderBy('name')->get(),
            'payees' => Payee::query()->orderBy('name')->get(),
            'cashAccounts' => CashAccount::query()->orderBy('name')->get(),
            'methodLabels' => $this->methodLabels(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Expense::class);

        return view('dashboard.finance.expenses.create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $data = $this->validateExpense($request);
        $data = $this->applyAttachment($request, $data);

        $expense = $this->expenses->create($data, $request->user());

        return redirect()
            ->route('dashboard.finance.expenses.show', $expense)
            ->with('success', __('expenses.created_notification'));
    }

    public function show(Expense $expense): View
    {
        $this->authorize('view', $expense);

        $expense->load(['expenseCategory', 'payee', 'cashAccount', 'creator', 'approver', 'payer', 'voider', 'cashTransaction']);

        return view('dashboard.finance.expenses.show', [
            'expense' => $expense,
            'methodLabels' => $this->methodLabels(),
        ]);
    }

    public function edit(Expense $expense): View
    {
        $this->authorize('update', $expense);

        return view('dashboard.finance.expenses.edit', array_merge(
            $this->formOptions(),
            ['expense' => $expense]
        ));
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);

        $data = $this->validateExpense($request, forUpdate: true);
        $data = $this->applyAttachment($request, $data);

        // Workflow status is never accepted from this form — it can only
        // change through the dedicated approve/pay/void actions below,
        // each of which goes through ExpenseService. The model's own
        // saving() guard is a second, independent backstop against
        // mutating a paid/void record even if this were ever bypassed.
        $expense->update($data);

        return redirect()
            ->route('dashboard.finance.expenses.show', $expense)
            ->with('success', __('expenses.updated_notification'));
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('approve', $expense);

        $this->expenses->approve($expense, $request->user());

        return back()->with('success', __('expenses.approved_notification'));
    }

    public function pay(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('pay', $expense);

        try {
            $this->expenses->pay($expense, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', __('expenses.paid_notification'));
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('void', $expense);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->expenses->void($expense, $request->user(), $data['void_reason']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', __('expenses.voided_notification'));
    }

    public function attachment(Expense $expense): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('view', $expense);

        abort_unless($expense->attachment_path, 404);

        $disk = Storage::disk(config('filesystems.uploads.private'));
        abort_unless($disk->exists($expense->attachment_path), 404);

        return response()->stream(
            fn () => print($disk->get($expense->attachment_path)),
            200,
            [
                'Content-Type' => $expense->attachment_type ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.addslashes($expense->attachment_name ?: 'attachment').'"',
            ]
        );
    }

    /**
     * @return array{title:string, amount:string, currency:string, expense_date:string, cash_account_id:int, expense_category_id:?int, payee_id:?int, payment_method:?string, external_reference:?string, description:?string, notes:?string, status?:string}
     */
    private function validateExpense(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'max:3'],
            'expense_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'payee_id' => ['nullable', 'integer', 'exists:payees,id'],
            'payment_method' => ['nullable', Rule::in([
                CashTransaction::METHOD_CASH,
                CashTransaction::METHOD_CARD,
                CashTransaction::METHOD_BANK,
                CashTransaction::METHOD_TRANSFER,
            ])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:'.implode(',', self::ATTACHMENT_MIMES)],
        ];

        if (! $forUpdate) {
            // Mirrors ExpenseForm's create-only status field exactly:
            // only draft or immediately-paid creation is supported here —
            // never approved/void on create.
            $rules['status'] = ['required', Rule::in([Expense::STATUS_DRAFT, Expense::STATUS_PAID])];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyAttachment(Request $request, array $data): array
    {
        unset($data['attachment']);

        if (! $request->hasFile('attachment')) {
            return $data;
        }

        $file = $request->file('attachment');
        $safeName = time().'_'.Str::random(8).'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs('expenses', $safeName, config('filesystems.uploads.private'));

        return array_merge($data, ['attachment_path' => $path], Expense::attachmentMetadataFrom($path));
    }

    /**
     * @return array{categories: \Illuminate\Support\Collection, payees: \Illuminate\Support\Collection, cashAccounts: \Illuminate\Support\Collection, methodLabels: array<string,string>}
     */
    private function formOptions(): array
    {
        return [
            'categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'payees' => Payee::query()->where('is_active', true)->orderBy('name')->get(),
            'cashAccounts' => CashAccount::query()->where('is_active', true)->orderBy('name')->get(),
            'methodLabels' => $this->methodLabels(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function methodLabels(): array
    {
        return [
            CashTransaction::METHOD_CASH => __('expenses.method_cash'),
            CashTransaction::METHOD_CARD => __('expenses.method_card'),
            CashTransaction::METHOD_BANK => __('expenses.method_bank'),
            CashTransaction::METHOD_TRANSFER => __('expenses.method_transfer'),
        ];
    }
}
