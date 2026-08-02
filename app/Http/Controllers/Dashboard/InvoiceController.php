<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoicePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        InvoicePaymentService $payments,
    ): RedirectResponse
    {
        $data = $request->validated();
        $actorId = $request->user()->id;
        $invoice = DB::transaction(function () use ($data, $calculator, $payments, $actorId, $request) {
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

            $invoiceData = [
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'],
                'customer_name' => $student->full_name,
                'currency' => InvoiceCalculationService::CURRENCY,
                'subtotal_amount' => $calculation['subtotal'],
                'total_amount' => $calculation['total_amount'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? '0.00',
                'discount_amount' => $calculation['discount_amount'],
                'paid_amount' => '0.00',
                'remaining_amount' => $calculation['total_amount'],
                'status' => Invoice::STATUS_UNPAID,
                'cash_account_id' => null,
                'payment_method' => null,
                'paid_at' => null,
                'due_date' => $data['due_date'],
                'created_by' => $actorId,
            ];

            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }

            $invoice = Invoice::create($invoiceData);
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

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
                $payments->record(
                    invoiceId: $invoice->id,
                    cashAccountId: (int) $data['cash_account_id'],
                    paymentMethod: $data['payment_method'],
                    amount: $calculation['paid_amount'],
                    idempotencyKey: (string) Str::uuid(),
                    actor: $request->user(),
                    reference: 'Первоначальная оплата по счёту '.$invoice->display_number,
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

    public function pay(Request $request, Invoice $invoice, InvoicePaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'payment_method' => ['required', 'in:cash,bank,card,transfer'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $payments->record(
            invoiceId: $invoice->id,
            cashAccountId: (int) $data['cash_account_id'],
            paymentMethod: $data['payment_method'],
            amount: (string) $data['amount'],
            idempotencyKey: $data['idempotency_key'],
            actor: $request->user(),
            reference: 'Оплата по счёту '.$invoice->display_number,
        );

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

    public function generateMonthlyInvoices(): void
    {
        abort(410, 'Автоматическое создание ежемесячных счетов временно отключено до безопасной реализации F1B.');
    }
}
