<?php

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\DuplicateOpenInvoiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChargeAndCollectRequest;
use App\Http\Requests\StoreModernInvoicePaymentRequest;
use App\Http\Requests\StoreRefundRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\SchoolSetting;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Services\Finance\ChargeAndCollectService;
use App\Services\Finance\InvoiceCancellationService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\ServiceCoverageService;
use App\Services\Finance\StudentFinanceSummaryService;
use App\Support\FinanceShareRecipient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FinanceOperationsController extends Controller
{
    public function __construct(private StudentFinanceSummaryService $summaries)
    {
        $this->middleware('permission:view invoices')->only(['workspace', 'student', 'statement', 'statementPdf', 'receipt', 'receiptPdf', 'refundReceipt']);
        $this->middleware('permission:manage invoices')->only(['createPayment', 'storePayment', 'chargeCreate', 'chargeStore']);
        $this->middleware('permission:void invoices')->only(['voidInvoice']);
        $this->middleware('permission:refund payments')->only(['createRefund', 'storeRefund']);
    }

    public function workspace(Request $request): View
    {
        $invoices = Invoice::query();
        $payments = InvoicePayment::query();
        $students = Student::query()->with(['currentEnrollment.academicYear', 'invoices.payments'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->input('q'));
                $query->where(fn ($query) => $query
                    ->where('id', ctype_digit($term) ? (int) $term : -1)
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereRaw("TRIM(CONCAT(COALESCE(last_name_ru,''),' ',COALESCE(first_name_ru,''),' ',COALESCE(patronymic_ru,''))) LIKE ?", ["%{$term}%"]));
            })
            ->when($request->filled('academic_year_id'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id'))))
            ->when($request->filled('status'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->where('status', $request->input('status'))))
            ->when($request->boolean('overdue'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->overdue()))
            ->when($request->filled('date_from'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from'))))
            ->when($request->filled('date_to'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to'))))
            ->orderBy('last_name_ru')->orderBy('first_name_ru')->paginate(25)->withQueryString();

        $summaries = $this->summaries->summarizeMany($students->getCollection());
        $rows = $students->getCollection()->map(fn (Student $student) => [
            'student' => $student,
            'summary' => $summaries->get($student->id),
        ]);
        $students->setCollection($rows);

        return view('dashboard.finance.workspace', [
            'students' => $students,
            'years' => AcademicYear::orderByDesc('start_date')->get(),
            'totals' => [
                'invoiced' => $this->money($invoices->sum('total_amount')),
                'paid' => $this->money($payments->sum('amount')),
                'remaining' => $this->money(Invoice::sum('remaining_amount')),
                'overdue' => $this->money(Invoice::overdue()->sum('remaining_amount')),
                'today' => $this->money(InvoicePayment::whereDate('paid_at', today())->sum('amount')),
            ],
        ]);
    }

    public function student(Student $student, ServiceCoverageService $coverageService): View
    {
        $student->load(['currentEnrollment.academicYear', 'currentEnrollment.stage', 'currentEnrollment.grade', 'currentEnrollment.schoolClass', 'invoices.academicYear', 'invoices.items.fee', 'invoices.installments.payments', 'invoices.payments.cashAccount', 'invoices.payments.creator', 'enrollments.serviceSubscriptions.fee', 'enrollments.serviceSubscriptions.invoiceItems']);
        $summary = $this->summaries->summarize($student);
        $subscriptions = $student->enrollments->flatMap->serviceSubscriptions->sortByDesc('created_at')->values();
        $coverages = ServiceCoverage::with(['fee', 'feePrice', 'invoiceItem.invoice'])
            ->where('student_id', $student->id)->latest()->get();
        $coveragePrices = FeePrice::active()->whereIn('fee_id', $coverages->pluck('fee_id'))
            ->orderBy('start_date')->get()->groupBy('fee_id');
        $coveredItemIds = $coverages->pluck('invoice_item_id');
        $coverageSources = $student->invoices->flatMap->items->whereNotIn('id', $coveredItemIds)
            ->map(function ($item) use ($coverageService): array {
                try {
                    $price = $coverageService->sourceTariff($item);

                    return ['item' => $item, 'price' => $price, 'billing_unit' => $price->payment_period, 'reason' => null];
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    return ['item' => $item, 'price' => null, 'billing_unit' => null, 'reason' => collect($exception->errors())->flatten()->first()];
                }
            })->values();
        $history = collect()
            ->concat($summary['invoices']->map(fn ($invoice) => ['at' => $invoice->created_at, 'label' => 'Создан счёт', 'text' => $invoice->display_number]))
            ->concat($summary['payments']->map(fn ($payment) => ['at' => $payment->paid_at ?? $payment->created_at, 'label' => 'Принят платёж', 'text' => $payment->payment_number]))
            ->concat($summary['adjustments']->map(fn ($adjustment) => ['at' => $adjustment->approved_at, 'label' => $adjustment->kind === 'debit' ? 'Доначисление' : 'Кредит', 'text' => $adjustment->total_difference.' EGP']))
            ->concat($summary['promises']->map(fn ($promise) => ['at' => $promise->created_at, 'label' => 'Обещание оплаты', 'text' => $promise->promised_amount.' EGP · '.$promise->status]))
            ->sortByDesc('at')->values();

        return view('dashboard.finance.student', compact('student', 'summary', 'subscriptions', 'coverages', 'coveragePrices', 'coverageSources', 'history'));
    }

    public function statement(Student $student): View
    {
        return view('dashboard.finance.statement', $this->statementData($student));
    }

    public function statementPdf(Student $student)
    {
        return Pdf::loadView('dashboard.finance.statement', $this->statementData($student) + ['pdf' => true])
            ->setPaper('a4')
            ->download('student-finance-'.$student->id.'.pdf');
    }

    public function createPayment(Invoice $invoice, InvoicePaymentService $service): View
    {
        abort_if($invoice->status === Invoice::STATUS_PAID || bccomp((string) $invoice->remaining_amount, '0.00', 2) <= 0, 422, 'Счёт уже полностью оплачен.');
        $invoice->load(['student', 'installments' => fn ($query) => $query->where('remaining_amount', '>', '0')->orderBy('sequence'), 'items.fee']);

        // Finance V2, Phase 1B — a multi-item invoice only gets per-line
        // allocation UI when it is "allocation-clean" (every payment so far
        // is fully represented in PaymentAllocation rows). A pre-Phase-1
        // invoice with historical unallocated payments cannot safely show a
        // "remaining per line" number, so it keeps Phase 1A's unallocated
        // behaviour instead (docs/finance-v2-architecture.md §19 Phase 1B).
        $allocationClean = $invoice->items->count() > 1 && $service->isAllocationClean($invoice);
        $remainingByItem = $allocationClean ? $service->remainingAllocatableByItem($invoice) : collect();

        return view('dashboard.finance.payments.create', [
            'invoice' => $invoice,
            'cashAccounts' => CashAccount::where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
            'allocationClean' => $allocationClean,
            'remainingByItem' => $remainingByItem,
        ]);
    }

    public function storePayment(StoreModernInvoicePaymentRequest $request, Invoice $invoice, InvoicePaymentService $service): RedirectResponse
    {
        $existing = InvoicePayment::where('idempotency_key', $request->string('idempotency_key'))->exists();

        // Finance V2, Phase 1B — mirrors createPayment()'s allocation-clean
        // gate exactly: only a multi-item, allocation-clean invoice both
        // offers and requires an explicit per-item split here; every other
        // invoice keeps Phase 1A's null-allocations fallback untouched.
        $allocations = null;
        $items = $invoice->items()->get();
        if ($items->count() > 1 && $service->isAllocationClean($invoice)) {
            $submitted = collect($request->input('allocations', []));
            $allocations = $items
                ->map(function ($item) use ($submitted) {
                    $raw = $submitted->get($item->id);

                    return $raw !== null && bccomp((string) $raw, '0.00', 2) > 0
                        ? ['invoice_item_id' => $item->id, 'amount' => (string) $raw]
                        : null;
                })
                ->filter()
                ->values()
                ->all();
        }

        $payment = $service->record(
            invoiceId: $invoice->id,
            cashAccountId: CashAccount::resolvePaymentAccountId((string) $request->input('payment_method'), $request->integer('cash_account_id')),
            amount: (string) $request->input('amount'),
            paymentMethod: (string) $request->input('payment_method'),
            idempotencyKey: (string) $request->input('idempotency_key'),
            actor: $request->user(),
            reference: 'Оплата по счёту '.$invoice->display_number,
            notes: $request->input('notes'),
            installmentId: $request->integer('invoice_installment_id') ?: null,
            allocations: $allocations,
        );

        return redirect()->route('dashboard.payments.receipt', $payment)
            ->with('success', $existing ? 'Повторная отправка не создала новый платёж.' : 'Платёж успешно принят.');
    }

    public function receipt(InvoicePayment $invoicePayment): View
    {
        return view('dashboard.finance.payments.receipt', $this->receiptData($invoicePayment));
    }

    public function receiptPdf(InvoicePayment $invoicePayment)
    {
        $data = $this->receiptData($invoicePayment);

        return Pdf::loadView('dashboard.finance.payments.receipt-pdf', $data)
            ->setPaper('a4')->download(($invoicePayment->payment_number ?: 'payment').'.pdf');
    }

    public function chargeCreate(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear']);
        $year = $student->currentEnrollment?->academicYear;

        return view('dashboard.finance.charge.create', [
            'student' => $student,
            'year' => $year,
            'fees' => Fee::active()->with('prices')->orderBy('category')->orderBy('name_ru')->get(),
            'cashAccounts' => CashAccount::where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function chargeStore(StoreChargeAndCollectRequest $request, Student $student, ChargeAndCollectService $service): RedirectResponse
    {
        try {
            $result = $service->chargeAndCollect(
                $student,
                $request->validated(),
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DuplicateOpenInvoiceException $exception) {
            return back()
                ->withInput()
                ->withErrors(['fee_id' => $exception->getMessage()])
                ->with('existing_invoice_id', $exception->invoiceId);
        }

        if ($result['payment']) {
            return redirect()->route('dashboard.payments.receipt', $result['payment'])
                ->with('success', 'Счёт создан, оплата принята.');
        }

        return redirect()->route('dashboard.invoices.show', $result['invoice'])
            ->with('success', 'Счёт создан без оплаты.');
    }

    public function voidInvoice(Request $request, Invoice $invoice, InvoiceCancellationService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $service->void($invoice, $data['reason'], $request->user());

        return redirect()->route('dashboard.invoices.show', $invoice)
            ->with('success', 'Счёт аннулирован.');
    }

    public function createRefund(InvoicePayment $invoicePayment): View
    {
        abort_if(bccomp($invoicePayment->refundableAmount(), '0.00', 2) <= 0, 422, 'По этому платежу нет суммы, доступной к возврату.');

        $invoicePayment->load(['invoice.student', 'cashAccount', 'allocations.item.fee']);

        // Finance V2, Phase 1D — the per-line split UI is only offered when
        // there is more than one PaymentAllocation to choose between; a
        // zero- or single-allocation payment keeps the plain amount/reason
        // form unchanged (the service auto-allocates or stays unattributed
        // on its own — see InvoiceRefundService::refund()). These figures
        // are advisory display only; the service is the sole source of
        // truth for what a refund may actually do.
        $refundLines = $invoicePayment->allocations->count() > 1
            ? $invoicePayment->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'label' => $allocation->item->fee?->name_ru ?? $allocation->item->description,
                'allocated' => (string) $allocation->amount,
                'refunded' => $allocation->refundedAmount(),
                'remaining' => bcsub((string) $allocation->amount, $allocation->refundedAmount(), 2),
                'non_refundable' => (bool) $allocation->item->is_non_refundable,
            ])
            : collect();

        return view('dashboard.finance.refunds.create', [
            'payment' => $invoicePayment,
            'refundable' => $invoicePayment->refundableAmount(),
            'idempotencyKey' => (string) Str::uuid(),
            'refundLines' => $refundLines,
        ]);
    }

    public function storeRefund(StoreRefundRequest $request, InvoicePayment $invoicePayment, InvoiceRefundService $service): RedirectResponse
    {
        $data = $request->validated();
        $existing = PaymentRefund::where('idempotency_key', $data['idempotency_key'])->exists();

        // Finance V2, Phase 1D — mirrors storePayment()'s allocation-split
        // gate: only a payment with more than one PaymentAllocation offers
        // (and forwards) an explicit per-line split here; every other
        // payment keeps allocations omitted, letting the service resolve
        // it (zero-allocation compatibility, or single-allocation
        // auto-attribution). Purely advisory shaping of the request — all
        // authoritative validation happens inside InvoiceRefundService.
        $allocations = null;
        $paymentAllocations = $invoicePayment->allocations()->get();
        if ($paymentAllocations->count() > 1) {
            $submitted = collect($data['allocations'] ?? []);
            $allocations = $paymentAllocations
                ->map(function ($allocation) use ($submitted) {
                    $raw = $submitted->get($allocation->id);

                    return $raw !== null && bccomp((string) $raw, '0.00', 2) > 0
                        ? ['payment_allocation_id' => $allocation->id, 'amount' => (string) $raw]
                        : null;
                })
                ->filter()
                ->values()
                ->all();
        }

        $refund = $service->refund(
            invoicePaymentId: $invoicePayment->id,
            amount: (string) $data['amount'],
            reason: (string) $data['reason'],
            idempotencyKey: (string) $data['idempotency_key'],
            actor: $request->user(),
            allocations: $allocations,
        );

        return redirect()->route('dashboard.refunds.receipt', $refund)
            ->with('success', $existing ? 'Повторная отправка не создала новый возврат.' : 'Возврат оформлен.');
    }

    public function refundReceipt(PaymentRefund $paymentRefund): View
    {
        $paymentRefund->load(['originalPayment', 'invoice.student', 'invoice.academicYear', 'cashAccount', 'executor']);

        return view('dashboard.finance.refunds.receipt', [
            'refund' => $paymentRefund,
            'settings' => SchoolSetting::current(),
        ]);
    }

    private function receiptData(InvoicePayment $payment): array
    {
        $payment->load(['invoice.student.representatives', 'invoice.academicYear', 'invoice.payments', 'invoice.items', 'installment', 'cashAccount', 'creator']);
        $ordered = $payment->invoice->payments->sortBy(fn ($item) => sprintf('%s-%010d', ($item->paid_at ?? $item->created_at)?->format('YmdHis.u'), $item->id));
        $through = $ordered->takeUntil(fn ($item) => $item->id === $payment->id)->push($payment)->unique('id');
        $paidThrough = $this->money($through->sum('amount'));
        $previouslyPaid = bcsub($paidThrough, (string) $payment->amount, 2);

        return [
            'payment' => $payment, 'invoice' => $payment->invoice, 'settings' => SchoolSetting::current(),
            'previouslyPaid' => $previouslyPaid,
            'remainingAfter' => bcsub((string) $payment->invoice->total_amount, $paidThrough, 2),
            'methodLabels' => ['cash' => 'Наличные', 'card' => 'Банковская карта', 'bank' => 'Банковский перевод'],
            'shareRecipient' => FinanceShareRecipient::forStudent($payment->invoice->student),
        ];
    }

    private function statementData(Student $student): array
    {
        $student->load([
            'currentEnrollment.academicYear',
            'currentEnrollment.grade',
            'currentEnrollment.schoolClass',
            'invoices.academicYear',
            'invoices.items.fee',
            'invoices.payments.cashAccount',
        ]);

        return [
            'student' => $student,
            'summary' => $this->summaries->summarize($student),
            'settings' => SchoolSetting::current(),
            'pdf' => false,
        ];
    }

    private function money($value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
