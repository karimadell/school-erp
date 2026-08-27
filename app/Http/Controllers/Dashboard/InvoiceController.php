<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\Student;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoicePaymentService;
use App\Support\FinanceShareRecipient;
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

        $academicYears = AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->get();
        $fees = $feesQuery->orderBy('category')->orderBy('name_ru')->get();

        return view('dashboard.invoices.create', [
            'students' => Student::with('grade')->orderBy('name')->get(),
            'academicYears' => $academicYears,
            'cashAccounts' => CashAccount::excludingOwner()->orderBy('name')->get(),
            'grades' => Grade::ordered()->get(),
            'fees' => $fees,
            'mealPlans' => MealPlan::active()->orderBy('name_ru')->get(),
            'priceRows' => $this->currentPriceRows($fees, $academicYears),
        ]);
    }

    /**
     * The live total the create-invoice screen's JS previews before
     * submit must reflect the exact same rows InvoiceCalculationService
     * would actually pick — stale/inactive/wrong-year FeePrice rows (e.g.
     * last year's tuition price still sitting on the same Fee) were being
     * sent to the browser unfiltered and unordered, so the preview total
     * could diverge from the server's authoritative total. An employee
     * paying "the full amount" as the (wrong) preview showed it then had
     * their submission rejected by the server's own overpayment guard —
     * this is the exact "invoice did not save" UAT symptom. Filtering by
     * the same FeePrice::sellable() scope InvoiceCalculationService's own
     * resolver query now composes (active, EGP, date-current), restricted
     * to the academic years this screen actually offers, and sorted to
     * match InvoiceCalculationService::resolvePrice()'s own tie-break
     * (start_date desc, id desc) closes that gap without duplicating its
     * more elaborate grade/enrollment-mode fallback matching.
     *
     * @param  \Illuminate\Support\Collection<int, Fee>  $fees
     * @param  \Illuminate\Support\Collection<int, AcademicYear>  $academicYears
     * @return array<int, array<string, mixed>>
     */
    private function currentPriceRows($fees, $academicYears): array
    {
        $prices = FeePrice::query()
            ->whereIn('fee_id', $fees->pluck('id'))
            ->whereIn('academic_year_id', $academicYears->pluck('id'))
            ->sellable()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return $prices->map(fn (FeePrice $price) => [
            'fee_id' => $price->fee_id,
            'amount' => (float) $price->amount,
            'grade_group' => $price->grade_group,
            'payment_period' => $price->payment_period,
            'size' => $price->size,
            'item' => $price->item,
            'option_type' => $price->option_type,
            'option_value' => $price->option_value,
        ])->all();
    }

    public function store(
        StoreInvoiceRequest $request,
        InvoiceCalculationService $calculator,
        InvoicePaymentService $payments,
    ): RedirectResponse {
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
                pricingDate: $data['pricing_date'],
                academicYearId: $academicYear->id,
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
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'paid_amount' => '0.00',
                    'remaining_amount' => $line['amount'],
                    'metadata' => $line['metadata'],
                    'is_non_refundable' => Fee::findOrFail($line['fee_id'])->is_non_refundable,
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
                    cashAccountId: CashAccount::resolvePaymentAccountId($data['payment_method'], isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null),
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

    public function show(Invoice $invoice): View
    {
        $invoice->load([
            'student.grade', 'student.representatives', 'academicYear', 'items.fee', 'items.subscription',
            'fees',
            'cashAccount',
            'payments.cashAccount',
            'payments.creator',
            'installments.payments',
        ]);

        $shareRecipient = FinanceShareRecipient::forStudent($invoice->student);

        return view('dashboard.invoices.show', compact('invoice', 'shareRecipient'));
    }

    public function print(Invoice $invoice): View
    {
        $invoice->load([
            'student.grade',
            'items.fee',
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
            'items.fee',
            'fees',
            'cashAccount',
            'payments.cashAccount',
        ]);

        $pdf = Pdf::loadView('dashboard.invoices.pdf', compact('invoice'));

        return $pdf->download('invoice-'.$invoice->id.'.pdf');
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
        // Phase 0 safety lockdown: this refund path used float math, wrote a
        // negative InvoicePayment with an unsupported method, had no
        // idempotency, and ignored non-refundable line items. A canonical,
        // idempotent refund service is scheduled for Phase 1; until then
        // refunds are disabled rather than allowed to corrupt balances.
        abort(410, 'Устаревшая форма возврата отключена. Безопасный возврат будет добавлен в следующем этапе.');
    }

    public function generateMonthlyInvoices(): void
    {
        abort(410, 'Автоматическое создание ежемесячных счетов временно отключено до безопасной реализации F1B.');
    }
}
