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
 * unified Приход type selector (IncomeEntryController::donation()/
 * buffet()/other() redirect here) — no standalone sidebar entry, mirroring
 * how income.students has none either.
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

    /**
     * Every "locked" workflow (Donation, Buffet, …) maps its own stable
     * ?type= discriminator to a real RevenueCategory.code and its own
     * page title. This is the SINGLE source of truth for that mapping —
     * used identically by create() (to preselect/display) and store() (to
     * resolve the category SERVER-SIDE, overriding whatever
     * revenue_category_id a client actually submitted). No numeric IDs
     * are ever hardcoded; a category is always resolved fresh by its
     * stable code.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function lockedRevenueTypes(): array
    {
        return [
            'donation' => [RevenueCategory::CODE_DONATION, 'revenues.donation_page_title'],
            'buffet' => [RevenueCategory::CODE_BUFFET, 'revenues.buffet_page_title'],
        ];
    }

    public function create(Request $request): View
    {
        $this->authorize('create', RevenueEntry::class);

        // Owner-approved Finance category separation (pre-go-live) — each
        // locked type maps to its own dedicated RevenueCategory row and
        // its own page title; 'buffet' is never the legacy 'cafeteria'
        // category, and the two can never be confused here since each
        // type is resolved by its own real, distinct code.
        $lockedTypes = $this->lockedRevenueTypes();
        $type = $request->string('type')->toString();
        $lockedCategory = null;
        $lockedType = null;
        $pageTitle = __('revenues.other_page_title');
        if (isset($lockedTypes[$type])) {
            [$code, $titleKey] = $lockedTypes[$type];
            $lockedCategory = RevenueCategory::query()->where('code', $code)->first();
            $lockedType = $type;
            $pageTitle = __($titleKey);
        }

        return view('dashboard.finance.income.revenue.create', array_merge(
            $this->formOptions(),
            ['lockedCategory' => $lockedCategory, 'lockedType' => $lockedType, 'pageTitle' => $pageTitle]
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', RevenueEntry::class);

        $data = $this->validateRevenue($request);
        $data = $this->applyLockedCategory($request, $data);
        $this->forbidControlledCategory($request, $data);
        $data = $this->applyAttachment($request, $data);

        $entry = $this->revenues->create($data, $request->user());

        return redirect()
            ->route('dashboard.finance.income.revenue.show', $entry)
            ->with('success', __('revenues.created_notification'));
    }

    /**
     * Server-side authoritative category lock. When the submission
     * carries a recognized workflow discriminator (the same hidden
     * "type" field create()'s locked form renders), the category is
     * re-resolved here from RevenueCategory's own stable code and
     * OVERRIDES whatever revenue_category_id the client actually
     * submitted — a tampered hidden field (e.g. a crafted Buffet POST
     * carrying the legacy cafeteria or school_food id) can never persist
     * as anything other than the locked category. Unlocked ("Прочий
     * приход") submissions have no "type" and are entirely unaffected —
     * the operator's own freely-selected active category passes through
     * exactly as validated.
     */
    private function applyLockedCategory(Request $request, array $data): array
    {
        $type = $request->string('type')->toString();
        $lockedTypes = $this->lockedRevenueTypes();
        if (! isset($lockedTypes[$type])) {
            return $data;
        }

        [$code] = $lockedTypes[$type];
        $category = RevenueCategory::query()->where('code', $code)->first();
        if ($category) {
            $data['revenue_category_id'] = $category->id;
        }

        return $data;
    }

    /**
     * Finance income workflow UX corrective — the categories with their
     * own dedicated operational card/workflow. school_food never has a
     * locked ?type= entry at all (Employee Stolovaya is a wholly separate
     * controller, never reached through this one — see
     * EmployeeFoodPurchaseService), so it can never legitimately pass the
     * check in forbidControlledCategory() below; buffet/donation DO have a
     * locked type and are permitted there, but only when the current
     * request is genuinely that locked flow.
     *
     * @return array<int, string>
     */
    private function controlledCategoryCodes(): array
    {
        return [
            RevenueCategory::CODE_SCHOOL_FOOD,
            RevenueCategory::CODE_BUFFET,
            RevenueCategory::CODE_DONATION,
        ];
    }

    /**
     * Stolovaya Phase 2 corrective, generalized (owner-approved Finance
     * income workflow UX pass): school_food/buffet/donation are CONTROLLED
     * revenue categories — each already has its own dedicated operational
     * card/workflow, so none of them may be created through this generic,
     * unstructured "Прочий приход" path. Runs AFTER applyLockedCategory(),
     * so a locked (buffet/donation) submission's revenue_category_id has
     * already been forcibly resolved to its own real category by then —
     * this check permits exactly that case (the resolved category matches
     * the request's own locked ?type=, via the SAME lockedRevenueTypes()
     * mapping applyLockedCategory() uses — never a second, duplicated
     * mapping) and rejects every other way a controlled category's id
     * could end up here: freely picked from the (now-excluded, see
     * formOptions()) generic dropdown, or crafted directly with no "type"
     * at all. school_food has no locked type entry to match, so it is
     * unconditionally rejected here exactly as before this generalization.
     *
     * Deliberately does NOT touch RevenueService — this is a boundary
     * check in the generic controller, before RevenueService::create() is
     * ever called, so a rejection here creates zero RevenueEntry and zero
     * CashTransaction. It does not weaken, remap, or bypass anything —
     * EmployeeFoodPurchaseService never calls this controller at all, so
     * the trusted domain path is entirely unaffected.
     */
    private function forbidControlledCategory(Request $request, array $data): void
    {
        $categoryId = (int) ($data['revenue_category_id'] ?? 0);
        if ($categoryId === 0) {
            return;
        }

        $controlled = RevenueCategory::query()
            ->whereIn('code', $this->controlledCategoryCodes())
            ->where('id', $categoryId)
            ->first();

        if (! $controlled) {
            return;
        }

        $type = $request->string('type')->toString();
        $lockedTypes = $this->lockedRevenueTypes();
        if (isset($lockedTypes[$type]) && $lockedTypes[$type][0] === $controlled->code) {
            return;
        }

        $message = $controlled->code === RevenueCategory::CODE_SCHOOL_FOOD
            ? 'Категория «Школьное питание» управляется отдельно — оформите питание сотрудника через «Столовая (сотрудник)».'
            : "Категория «{$controlled->name_ru}» управляется отдельно — используйте её собственную карточку на странице «Приход».";

        throw ValidationException::withMessages(['revenue_category_id' => $message]);
    }

    public function show(RevenueEntry $revenueEntry): View
    {
        $this->authorize('view', $revenueEntry);

        $revenueEntry->load(['category', 'cashAccount', 'creator', 'poster', 'reverser', 'cashTransaction', 'reversalTransaction']);

        return view('dashboard.finance.income.revenue.show', [
            'entry' => $revenueEntry,
        ]);
    }

    /**
     * Final Finance UX corrective — a read-only, printable receipt for a
     * RevenueEntry that has actually reached the ledger. A draft has no
     * CashTransaction yet, so there is nothing to receipt (404, same
     * reasoning as ExpenseController never exposing an attachment for a
     * record that was never posted). A reversed entry still gets a
     * receipt — its reversal is real financial history — but the
     * dedicated view renders a clear "Сторнирован" state instead of
     * presenting it as an ordinary valid payment receipt. Zero writes:
     * this method only loads and displays already-stored attributes.
     */
    public function receipt(RevenueEntry $revenueEntry): View
    {
        $this->authorize('view', $revenueEntry);
        abort_if($revenueEntry->isDraft(), 404);

        $revenueEntry->load(['category', 'cashAccount', 'creator', 'poster', 'reverser', 'cashTransaction', 'reversalTransaction']);

        return view('dashboard.finance.income.revenue.receipt', [
            'entry' => $revenueEntry,
            'settings' => \App\Models\SchoolSetting::current(),
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
            fn () => print ($disk->get($revenueEntry->attachment_path)),
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
            // Finance income workflow UX corrective (generalizes the
            // original Stolovaya Phase 2 school_food-only exclusion):
            // school_food/buffet/donation each already have their own
            // dedicated operational card/workflow, so none of them may be
            // freely picked from this generic "Прочий приход" selector —
            // see controlledCategoryCodes()/forbidControlledCategory() for
            // the server-side enforcement this dropdown exclusion mirrors.
            // Excluded by stable `code` (never the mutable name_ru — see
            // RevenueCategory's own class docblock). Every other active
            // category (fine, other, cafeteria, …) is unaffected; buffet's
            // and donation's own dedicated locked shortcuts are untouched
            // by this exclusion too, since neither renders this dropdown
            // at all (see the create view).
            'categories' => RevenueCategory::query()
                ->where('is_active', true)
                ->whereNotIn('code', $this->controlledCategoryCodes())
                ->orderBy('name_ru')->get(),
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
