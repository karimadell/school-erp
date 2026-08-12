<?php

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\DuplicateOpenInvoiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChargeAndCollectRequest;
use App\Http\Requests\StoreModernInvoicePaymentRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\PaymentRefund;
use App\Services\Finance\ChargeAndCollectService;
use App\Services\Finance\InvoiceCancellationService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\StudentFinanceSummaryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FinanceOperationsController extends Controller
{
    public function __construct(private StudentFinanceSummaryService $summaries)
    {
        $this->middleware('permission:view invoices')->only(['workspace', 'student', 'receipt', 'receiptPdf', 'refundReceipt']);
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

        $rows = $students->getCollection()->map(fn (Student $student) => ['student' => $student, 'summary' => $this->summaries->summarize($student)]);
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

    public function student(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear','currentEnrollment.stage','currentEnrollment.grade','currentEnrollment.schoolClass','invoices.academicYear','invoices.installments.payments','invoices.payments.cashAccount','invoices.payments.creator','enrollments.serviceSubscriptions.fee','enrollments.serviceSubscriptions.invoiceItems']);
        $summary = $this->summaries->summarize($student);
        $subscriptions = $student->enrollments->flatMap->serviceSubscriptions->sortByDesc('created_at')->values();
        $history = collect()
            ->concat($summary['invoices']->map(fn ($invoice) => ['at'=>$invoice->created_at,'label'=>'Создан счёт','text'=>$invoice->display_number]))
            ->concat($summary['payments']->map(fn ($payment) => ['at'=>$payment->paid_at ?? $payment->created_at,'label'=>'Принят платёж','text'=>$payment->payment_number]))
            ->sortByDesc('at')->values();

        return view('dashboard.finance.student', compact('student', 'summary', 'subscriptions', 'history'));
    }

    public function createPayment(Invoice $invoice): View
    {
        abort_if($invoice->status === Invoice::STATUS_PAID || bccomp((string) $invoice->remaining_amount, '0.00', 2) <= 0, 422, 'Счёт уже полностью оплачен.');
        $invoice->load(['student', 'installments' => fn ($query) => $query->where('remaining_amount','>','0')->orderBy('sequence')]);
        return view('dashboard.finance.payments.create', [
            'invoice' => $invoice,
            'cashAccounts' => CashAccount::where('is_active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storePayment(StoreModernInvoicePaymentRequest $request, Invoice $invoice, InvoicePaymentService $service): RedirectResponse
    {
        $existing = InvoicePayment::where('idempotency_key', $request->string('idempotency_key'))->exists();
        $payment = $service->record(
            invoiceId: $invoice->id,
            cashAccountId: $request->integer('cash_account_id'),
            amount: (string) $request->input('amount'),
            paymentMethod: (string) $request->input('payment_method'),
            idempotencyKey: (string) $request->input('idempotency_key'),
            actor: $request->user(),
            reference: 'Оплата по счёту '.$invoice->display_number,
            notes: $request->input('notes'),
            installmentId: $request->integer('invoice_installment_id') ?: null,
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
            'cashAccounts' => CashAccount::where('is_active', true)->orderBy('name')->get(),
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

        $invoicePayment->load(['invoice.student', 'cashAccount']);

        return view('dashboard.finance.refunds.create', [
            'payment' => $invoicePayment,
            'refundable' => $invoicePayment->refundableAmount(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeRefund(Request $request, InvoicePayment $invoicePayment, InvoiceRefundService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $existing = PaymentRefund::where('idempotency_key', $data['idempotency_key'])->exists();

        $refund = $service->refund(
            invoicePaymentId: $invoicePayment->id,
            amount: (string) $data['amount'],
            reason: (string) $data['reason'],
            idempotencyKey: (string) $data['idempotency_key'],
            actor: $request->user(),
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
        $payment->load(['invoice.student','invoice.academicYear','invoice.payments','invoice.items','installment','cashAccount','creator']);
        $ordered = $payment->invoice->payments->sortBy(fn ($item) => sprintf('%s-%010d', ($item->paid_at ?? $item->created_at)?->format('YmdHis.u'), $item->id));
        $through = $ordered->takeUntil(fn ($item) => $item->id === $payment->id)->push($payment)->unique('id');
        $paidThrough = $this->money($through->sum('amount'));
        $previouslyPaid = bcsub($paidThrough, (string) $payment->amount, 2);

        return [
            'payment'=>$payment, 'invoice'=>$payment->invoice, 'settings'=>SchoolSetting::current(),
            'previouslyPaid'=>$previouslyPaid,
            'remainingAfter'=>bcsub((string) $payment->invoice->total_amount, $paidThrough, 2),
            'methodLabels'=>['cash'=>'Наличные','card'=>'Банковская карта','bank'=>'Банковский перевод'],
        ];
    }

    private function money($value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
