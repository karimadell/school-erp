<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\Student;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Services\Finance\InvoiceCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view invoices')->only(['index', 'show', 'print', 'pdf']);
        $this->middleware('permission:manage invoices')->only(['create', 'store', 'pay', 'refund', 'generateMonthlyInvoices']);
    }

    public function index(): View
    {
        $invoices = Invoice::with(['student', 'cashAccount', 'fees'])
            ->latest()
            ->paginate(15);

        return view('dashboard.invoices.index', compact('invoices'));
    }

    public function create(): View
    {
        $feesQuery = Fee::with('prices');

        if (Schema::hasColumn('fees', 'is_active')) {
            $feesQuery->where('is_active', 1);
        }

        return view('dashboard.invoices.create', [
            'students' => Student::with('grade')->orderBy('name')->get(),
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->get(),
            'cashAccounts' => CashAccount::orderBy('name')->get(),
            'grades' => Grade::orderBy('id')->get(),
            'fees' => $feesQuery
                ->orderBy('category')
                ->orderBy('name_ru')
                ->get(),
        ]);
    }

    public function store(
        StoreInvoiceRequest $request,
        InvoiceCalculationService $calculator,
    ): RedirectResponse
    {
        $data = $request->validated();
        $invoice = DB::transaction(function () use ($data, $calculator) {
            $student = Student::query()->lockForUpdate()->findOrFail($data['student_id']);
            $academicYear = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $enrollmentExists = Enrollment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            if (! $academicYear->is_active || ! $enrollmentExists) {
                throw ValidationException::withMessages([
                    'student_id' => 'Зачисление ученика или выбранный учебный год изменились. Обновите страницу и повторите попытку.',
                ]);
            }

            $calculation = $calculator->calculate(
                items: $data['items'],
                discountType: $data['discount_type'] ?? null,
                discountValue: $data['discount_value'] ?? null,
                initialPaymentAmount: $data['initial_payment_amount'] ?? '0',
                pricingDate: now()->toDateString(),
            );

            $cashAccountId = $data['cash_account_id']
                ?? CashAccount::query()->orderBy('id')->lockForUpdate()->value('id');

            if (! $cashAccountId) {
                throw ValidationException::withMessages([
                    'cash_account_id' => 'В системе не настроена касса. Обратитесь к администратору.',
                ]);
            }

            $invoiceData = [
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'],
                'customer_name' => $student->full_name,
                'total_amount' => $calculation['total_amount'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? '0.00',
                'discount_amount' => $calculation['discount_amount'],
                'paid_amount' => $calculation['paid_amount'],
                'remaining_amount' => $calculation['remaining_amount'],
                'status' => $calculation['status'],
                // cash_account_id is currently NOT NULL. For an unpaid
                // invoice this is only the designated receiving account;
                // no payment or cash transaction is created.
                'cash_account_id' => $cashAccountId,
                // The current schema keeps payment_method NOT NULL. Until
                // F1B makes it nullable, "pending" explicitly means that no
                // payment method has been selected for this unpaid invoice.
                'payment_method' => bccomp($calculation['paid_amount'], '0.00', 2) > 0
                    ? $data['payment_method']
                    : 'pending',
                'paid_at' => $calculation['status'] === Invoice::STATUS_PAID ? now() : null,
                'due_date' => $data['due_date'],
            ];

            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }

            $invoice = Invoice::create($invoiceData);

            foreach ($calculation['line_items'] as $line) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'fee_id' => $line['fee_id'],
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                ]);

                // Transitional compatibility: classic invoice views still
                // read invoice_fee. Both records come from one calculation.
                $invoice->fees()->attach($line['fee_id'], [
                    'amount' => $line['amount'],
                    'item' => $line['item'],
                    'size' => $line['size'],
                    'option_type' => $line['option_type'],
                    'option_value' => $line['option_value'],
                ]);
            }

            if (bccomp($calculation['paid_amount'], '0.00', 2) > 0) {
                $this->recordInvoicePayment(
                    invoice: $invoice,
                    cashAccountId: (int) $data['cash_account_id'],
                    paymentMethod: $data['payment_method'],
                    amount: $calculation['paid_amount'],
                    referenceType: 'invoice',
                    description: 'Invoice #' . $invoice->id
                );
            }

            return $invoice;
        });

        return redirect()
            ->route('dashboard.invoices.print', $invoice)
            ->with('success', 'Счёт успешно создан. Все суммы рассчитаны в EGP.');
    }

    private function resolveFeeAmount(Fee $fee, ?Request $request = null): float
    {
        if (! $request) {
            return (float) ($fee->amount ?? $fee->base_price ?? 0);
        }

        $date = now()->toDateString();

        if (method_exists($fee, 'prices')) {
            $query = $fee->prices()
                ->where('start_date', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
                });

            if (Schema::hasColumn('fee_prices', 'is_active')) {
                $query->where('is_active', 1);
            }

            $filters = [
                'grade_group' => $request->input("grade_group.{$fee->id}"),
                'payment_period' => $request->input("payment_period.{$fee->id}"),
                'size' => $request->input("uniform_size.{$fee->id}"),
                'item' => $request->input("uniform_item.{$fee->id}"),
                'option_type' => $request->input("option_type.{$fee->id}"),
                'option_value' => $request->input("option_value.{$fee->id}"),
            ];

            foreach ($filters as $column => $value) {
                if (filled($value) && Schema::hasColumn('fee_prices', $column)) {
                    $query->where($column, $value);
                }
            }

            $price = $query->orderByDesc('start_date')->first();

            if ($price) {
                return (float) $price->amount;
            }
        }

        if (method_exists($fee, 'priceForDate')) {
            return (float) $fee->priceForDate($date);
        }

        return (float) ($fee->amount ?? $fee->base_price ?? 0);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load([
            'student.grade',
            'fees',
            'cashAccount',
            'payments.cashAccount',
            'payments.creator',
        ]);

        return view('dashboard.invoices.show', compact('invoice'));
    }

    public function print(Invoice $invoice): View
    {
        $invoice->load([
            'student.grade',
            'fees',
            'cashAccount',
            'payments.cashAccount',
        ]);

        return view('dashboard.invoices.print', compact('invoice'));
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load([
            'student.grade',
            'fees',
            'cashAccount',
            'payments.cashAccount',
        ]);

        $pdf = Pdf::loadView('dashboard.invoices.pdf', compact('invoice'));

        return $pdf->download('invoice-' . $invoice->id . '.pdf');
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'payment_method' => ['required', 'in:cash,bank,card,transfer'],
        ]);

        if ($invoice->status === Invoice::STATUS_PAID) {
            return back()->withErrors(['amount' => __('invoices.already_paid')]);
        }

        $paymentAmount = min((float) $data['amount'], (float) $invoice->remaining_amount);

        DB::transaction(function () use ($invoice, $data, $paymentAmount) {
            $invoice->paid_amount = (float) $invoice->paid_amount + $paymentAmount;
            $invoice->payment_method = $data['payment_method'];
            $invoice->cash_account_id = $data['cash_account_id'];
            $invoice->refreshPaymentStatus();

            $this->recordInvoicePayment(
                invoice: $invoice,
                cashAccountId: (int) $data['cash_account_id'],
                paymentMethod: $data['payment_method'],
                amount: $paymentAmount,
                referenceType: 'invoice_payment',
                description: 'Invoice payment #' . $invoice->id
            );
        });

        return back()->with('success', __('invoices.payment_received'));
    }

    public function refund(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
        ]);

        $refundAmount = min((float) $data['amount'], (float) $invoice->paid_amount);

        if ($refundAmount <= 0) {
            return back()->withErrors(['amount' => __('invoices.no_refundable_amount')]);
        }

        DB::transaction(function () use ($invoice, $data, $refundAmount) {
            $invoice->paid_amount = max((float) $invoice->paid_amount - $refundAmount, 0);
            $invoice->refreshPaymentStatus();

            $cashAccount = CashAccount::lockForUpdate()->findOrFail($data['cash_account_id']);

            // Balance is adjusted exactly once, by CashTransaction's own
            // created-event hook (see CashTransaction::booted()) — do not
            // also mutate $cashAccount->balance here, or the refund is
            // posted twice.
            CashTransaction::create([
                'cash_account_id' => $cashAccount->id,
                'amount' => $refundAmount,
                'type' => 'out',
                'description' => 'Refund invoice #' . $invoice->id,
            ]);

            InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'cash_account_id' => $cashAccount->id,
                'amount' => -$refundAmount,
                'payment_method' => 'refund',
                'paid_at' => now(),
                'reference' => 'Refund invoice #' . $invoice->id,
                'created_by' => auth()->id(),
            ]);
        });

        return back()->with('success', __('invoices.refunded'));
    }

    private function recordInvoicePayment(
        Invoice $invoice,
        int $cashAccountId,
        string $paymentMethod,
        string|float $amount,
        string $referenceType,
        string $description
    ): void {
        $cashAccount = CashAccount::lockForUpdate()->findOrFail($cashAccountId);

        // Balance is adjusted exactly once, by CashTransaction's own
        // created-event hook (see CashTransaction::booted()) — do not
        // also mutate $cashAccount->balance here, or the payment is
        // posted twice.
        CashTransaction::create([
            'cash_account_id' => $cashAccount->id,
            'amount' => $amount,
            'type' => 'in',
            'description' => $description . ' - ' . $paymentMethod,
        ]);

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'cash_account_id' => $cashAccount->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'paid_at' => now(),
            'reference' => $description,
            'created_by' => auth()->id(),
        ]);

        if (Schema::hasColumn('cash_accounts', 'account_id') && ! empty($cashAccount->account_id)) {
            $entry = JournalEntry::create([
                'entry_number' => 'JE-' . time() . '-' . $invoice->id,
                'entry_date' => now(),
                'reference_type' => $referenceType,
                'reference_id' => $invoice->id,
                'description' => $description,
                'created_by' => auth()->id(),
            ]);

            JournalItem::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cashAccount->account_id,
                'debit' => $amount,
                'credit' => 0,
                'description' => 'Cash received',
            ]);

            $revenueAccount = Account::where('code', '4000')->first();

            if ($revenueAccount) {
                JournalItem::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $revenueAccount->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Invoice revenue',
                ]);
            }
        }
    }

    public function generateMonthlyInvoices(): void
    {
        abort(410, 'Автоматическое создание ежемесячных счетов временно отключено до безопасной реализации F1B.');
    }
}
