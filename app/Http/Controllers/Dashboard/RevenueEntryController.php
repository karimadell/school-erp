<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Services\Finance\RevenueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart of the Non-Tuition Revenues V1 backend
 * (RevenueService/RevenueEntry/RevenueCategory), reached only from the
 * unified Приход type selector (IncomeEntryController::donation()/other()
 * redirect here) — no standalone sidebar entry, mirroring how
 * income.students has none either.
 *
 * Every status-changing mutation (create/post/reverse/delete) delegates to
 * RevenueService — this controller never calls RevenueEntry::create() or
 * mutates ledger/workflow state directly (see RevenueAtomicCreationTest::
 * test_no_application_code_bypasses_the_revenue_service_for_creation,
 * which fails the build if any app/ code bypasses the service).
 *
 * Filament's RevenueEntry/RevenueCategory resources are NOT part of this
 * integration pass (dashboard-native only, matching Expenses V1's own
 * precedent) — they remain a possible future technical-fallback addition,
 * not built here.
 */
class RevenueEntryController extends Controller
{
    private const ATTACHMENT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public function __construct(private RevenueService $revenues) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RevenueEntry::class);

        $entries = RevenueEntry::query()
            ->with(['category', 'cashAccount', 'creator'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('revenue_category_id'), fn ($q) => $q->where('revenue_category_id', $request->integer('revenue_category_id')))
            ->orderByDesc('revenue_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.finance.income.revenue.index', [
            'entries' => $entries,
            'categories' => RevenueCategory::query()->orderBy('name_ru')->get(),
            'filters' => [
                'status' => $request->string('status')->toString() ?: null,
                'revenue_category_id' => $request->integer('revenue_category_id') ?: null,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', RevenueEntry::class);

        $lockedCategory = null;
        if ($request->string('type')->toString() === 'donation') {
            $lockedCategory = RevenueCategory::query()->where('code', RevenueCategory::CODE_DONATION)->first();
        }

        return view('dashboard.finance.income.revenue.create', array_merge(
            $this->formOptions(),
            ['lockedCategory' => $lockedCategory]
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', RevenueEntry::class);

        $data = $this->validateRevenue($request);
        $data = $this->applyAttachment($request, $data);

        $entry = $this->revenues->create($data, $request->user());

        return redirect()
            ->route('dashboard.finance.income.revenue.show', $entry)
            ->with('success', __('revenues.created_notification'));
    }

    public function show(RevenueEntry $revenueEntry): View
    {
        $this->authorize('view', $revenueEntry);

        $revenueEntry->load(['category', 'cashAccount', 'creator', 'poster', 'reverser', 'cashTransaction', 'reversalTransaction']);

        return view('dashboard.finance.income.revenue.show', [
            'entry' => $revenueEntry,
        ]);
    }

    public function post(Request $request, RevenueEntry $revenueEntry): RedirectResponse
    {
        $this->authorize('post', $revenueEntry);

        try {
            $this->revenues->post($revenueEntry, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', __('revenues.posted_notification'));
    }

    public function reverse(Request $request, RevenueEntry $revenueEntry): RedirectResponse
    {
        $this->authorize('reverse', $revenueEntry);

        $data = $request->validate([
            'reversal_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->revenues->reverse($revenueEntry, $request->user(), $data['reversal_reason']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', __('revenues.reversed_notification'));
    }

    public function destroy(Request $request, RevenueEntry $revenueEntry): RedirectResponse
    {
        $this->authorize('delete', $revenueEntry);

        try {
            $this->revenues->deleteDraft($revenueEntry, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()
            ->route('dashboard.finance.income.revenue.index')
            ->with('success', __('revenues.deleted_notification'));
    }

    public function attachment(RevenueEntry $revenueEntry): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('view', $revenueEntry);

        abort_unless($revenueEntry->attachment_path, 404);

        $disk = Storage::disk(config('filesystems.uploads.private'));
        abort_unless($disk->exists($revenueEntry->attachment_path), 404);

        return response()->stream(
            fn () => print($disk->get($revenueEntry->attachment_path)),
            200,
            [
                'Content-Type' => $revenueEntry->attachment_type ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.addslashes($revenueEntry->attachment_name ?: 'attachment').'"',
            ]
        );
    }

    /**
     * @return array{revenue_category_id:int, amount:string, revenue_date:string, cash_account_id:int, payment_method:string, payer_name:?string, description:?string, notes:?string, status:string}
     */
    private function validateRevenue(Request $request): array
    {
        $rules = [
            'revenue_category_id' => ['required', 'integer', 'exists:revenue_categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'revenue_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'payment_method' => ['required', Rule::in([
                CashTransaction::METHOD_CASH,
                CashTransaction::METHOD_CARD,
                CashTransaction::METHOD_BANK,
                CashTransaction::METHOD_TRANSFER,
            ])],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:'.implode(',', self::ATTACHMENT_MIMES)],
            // Mirrors ExpenseController's create-only status field exactly:
            // only draft or immediately-posted creation is supported here —
            // never reversed on create.
            'status' => ['required', Rule::in([RevenueEntry::STATUS_DRAFT, RevenueEntry::STATUS_POSTED])],
        ];

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
        $path = $file->storeAs('revenue-entries', $safeName, config('filesystems.uploads.private'));

        return array_merge($data, ['attachment_path' => $path], RevenueEntry::attachmentMetadataFrom($path));
    }

    /**
     * @return array{categories: \Illuminate\Support\Collection, cashAccounts: \Illuminate\Support\Collection, methodLabels: array<string,string>}
     */
    private function formOptions(): array
    {
        return [
            'categories' => RevenueCategory::query()->where('is_active', true)->orderBy('name_ru')->get(),
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
            CashTransaction::METHOD_CASH => __('revenues.method_cash'),
            CashTransaction::METHOD_CARD => __('revenues.method_card'),
            CashTransaction::METHOD_BANK => __('revenues.method_bank'),
            CashTransaction::METHOD_TRANSFER => __('revenues.method_transfer'),
        ];
    }
}
