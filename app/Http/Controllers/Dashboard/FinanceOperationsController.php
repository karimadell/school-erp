<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreModernInvoicePaymentRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Services\Finance\InvoicePaymentService;
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
        $this->middleware('permission:view invoices')->only(['workspace', 'student', 'receipt', 'receiptPdf']);
        $this->middleware('permission:manage invoices')->only(['createPayment', 'storePayment']);
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
        $student->load(['currentEnrollment.academicYear','currentEnrollment.stage','currentEnrollment.grade','currentEnrollment.schoolClass','invoices.academicYear','invoices.payments.cashAccount','invoices.payments.creator','enrollments.serviceSubscriptions.fee','enrollments.serviceSubscriptions.invoiceItems']);
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
        $invoice->load('student');
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

    private function receiptData(InvoicePayment $payment): array
    {
        $payment->load(['invoice.student','invoice.academicYear','invoice.payments','cashAccount','creator']);
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
