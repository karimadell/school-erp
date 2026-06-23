<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class InvoiceController extends Controller
{
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
            'cashAccounts' => CashAccount::orderBy('name')->get(),
            'grades' => Grade::orderBy('id')->get(),
            'fees' => $feesQuery
                ->orderBy('category')
                ->orderBy('name_ru')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['nullable', 'exists:students,id'],

            'new_student' => ['nullable', 'array'],
            'new_student.name' => ['nullable', 'string', 'max:255'],
            'new_student.phone' => ['nullable', 'string', 'max:50'],
            'new_student.academic_year' => ['nullable', 'string', 'max:50'],
            'new_student.grade_id' => ['nullable', 'exists:grades,id'],

            'invoice_note' => ['nullable', 'string', 'max:1000'],

            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'payment_method' => ['required', 'in:cash,bank,card,transfer'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],

            'discount_type' => ['nullable', 'in:fixed,percent'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],

            'fees' => ['nullable', 'array'],
            'fees.*' => ['exists:fees,id'],

            'grade_group' => ['nullable', 'array'],
            'grade_group.*' => ['nullable', 'string'],

            'payment_period' => ['nullable', 'array'],
            'payment_period.*' => ['nullable', 'string'],

            'first_last_month' => ['nullable', 'array'],
            'first_last_month.*' => ['nullable', 'in:1'],

            'uniform_size' => ['nullable', 'array'],
            'uniform_size.*' => ['nullable', 'string'],

            'uniform_item' => ['nullable', 'array'],
            'uniform_item.*' => ['nullable', 'string'],

            'option_type' => ['nullable', 'array'],
            'option_type.*' => ['nullable', 'string'],

            'option_value' => ['nullable', 'array'],
            'option_value.*' => ['nullable', 'string'],
        ]);

        $hasExistingStudent = filled($data['student_id'] ?? null);
        $hasNewStudent = filled($data['new_student']['name'] ?? null);

        if (! $hasExistingStudent && ! $hasNewStudent) {
            return back()
                ->withErrors(['student_id' => __('invoices.select_or_create_student')])
                ->withInput();
        }

        $invoice = DB::transaction(function () use ($data, $hasExistingStudent, $request) {
            if ($hasExistingStudent) {
                $student = Student::with('grade')->findOrFail($data['student_id']);
            } else {
                $student = new Student();
                $student->name = $data['new_student']['name'];
                $student->phone = $data['new_student']['phone'] ?? null;
                $student->grade_id = $data['new_student']['grade_id'] ?? null;
                $student->save();
            }

            $student->load('grade');

            $fees = Fee::whereIn('id', $data['fees'] ?? [])
                ->get()
                ->unique('id');

            $feeAmounts = [];

            foreach ($fees as $fee) {
                $amount = $this->resolveFeeAmount($fee, $request);

                $paymentPeriod = $request->input("payment_period.{$fee->id}");

                $isStudyFee = in_array($fee->category, [
                    'tuition',
                    'tuition_regular',
                    'tuition_family',
                    'tuition_external',
                ], true);

                $takeFirstLastMonth =
                    $isStudyFee &&
                    $paymentPeriod === 'monthly' &&
                    $request->input("first_last_month.{$fee->id}") === '1';

                if ($takeFirstLastMonth) {
                    $amount *= 2;
                }

                $feeAmounts[$fee->id] = $amount;
            }

            $total = array_sum($feeAmounts);

            $discountType = $data['discount_type'] ?? null;
            $discountValue = (float) ($data['discount_value'] ?? 0);
            $discountAmount = 0;

            if ($discountType === 'percent') {
                $discountAmount = ($total * $discountValue) / 100;
            } elseif ($discountType === 'fixed') {
                $discountAmount = $discountValue;
            }

            $discountAmount = min($discountAmount, $total);
            $netAmount = max($total - $discountAmount, 0);

            $paidAmount = array_key_exists('paid_amount', $data) && $data['paid_amount'] !== null
                ? (float) $data['paid_amount']
                : $netAmount;

            $paidAmount = min($paidAmount, $netAmount);
            $remainingAmount = max($netAmount - $paidAmount, 0);

            $status = match (true) {
                $paidAmount <= 0 => Invoice::STATUS_UNPAID,
                $remainingAmount > 0 => Invoice::STATUS_PARTIAL,
                default => Invoice::STATUS_PAID,
            };

            $invoiceData = [
                'student_id' => $student->id,
                'customer_name' => $student->name,
                'total_amount' => $total,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'status' => $status,
                'cash_account_id' => $data['cash_account_id'],
                'payment_method' => $data['payment_method'],
                'paid_at' => $status === Invoice::STATUS_PAID ? now() : null,
            ];

            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['invoice_note'] ?? null;
            }

            $invoice = Invoice::create($invoiceData);

            foreach ($fees as $fee) {
                $feeId = $fee->id;

                $gradeGroup = $request->input("grade_group.{$feeId}");
                $paymentPeriod = $request->input("payment_period.{$feeId}");

                $optionType = $request->input("option_type.{$feeId}");
                $optionValue = $request->input("option_value.{$feeId}");

                $isStudyFee = in_array($fee->category, [
                    'tuition',
                    'tuition_regular',
                    'tuition_family',
                    'tuition_external',
                ], true);

                $takeFirstLastMonth =
                    $isStudyFee &&
                    $paymentPeriod === 'monthly' &&
                    $request->input("first_last_month.{$feeId}") === '1';

                if (! $optionType && ($gradeGroup || $paymentPeriod || $takeFirstLastMonth)) {
                    $optionType = 'study';

                    $parts = [];

                    if ($gradeGroup) {
                        $parts[] = $gradeGroup;
                    }

                    if ($paymentPeriod) {
                        $parts[] = $paymentPeriod;
                    }

                    if ($takeFirstLastMonth) {
                        $parts[] = 'first_last_month';
                    }

                    $optionValue = implode(' / ', $parts);
                }

                $invoice->fees()->attach($feeId, [
                    'amount' => $feeAmounts[$feeId] ?? 0,
                    'item' => $request->input("uniform_item.{$feeId}"),
                    'size' => $request->input("uniform_size.{$feeId}"),
                    'option_type' => $optionType,
                    'option_value' => $optionValue,
                ]);
            }

            if ($paidAmount > 0) {
                $this->recordInvoicePayment(
                    invoice: $invoice,
                    cashAccountId: (int) $data['cash_account_id'],
                    paymentMethod: $data['payment_method'],
                    amount: $paidAmount,
                    referenceType: 'invoice',
                    description: 'Invoice #' . $invoice->id
                );
            }

            return $invoice;
        });

        return redirect()
            ->route('dashboard.invoices.print', $invoice)
            ->with('success', __('invoices.saved_and_paid'));
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
            $cashAccount->decrement('balance', $refundAmount);

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
        float $amount,
        string $referenceType,
        string $description
    ): void {
        $cashAccount = CashAccount::lockForUpdate()->findOrFail($cashAccountId);
        $cashAccount->increment('balance', $amount);

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
        $students = Student::with('grade')->get();

        foreach ($students as $student) {
            $gradeFeesQuery = Fee::where('grade_id', $student->grade_id);

            if (Schema::hasColumn('fees', 'is_active')) {
                $gradeFeesQuery->where('is_active', 1);
            }

            $gradeFees = $gradeFeesQuery->get();

            if ($gradeFees->isEmpty()) {
                continue;
            }

            $feeAmounts = [];

            foreach ($gradeFees as $fee) {
                $feeAmounts[$fee->id] = $this->resolveFeeAmount($fee);
            }

            $total = array_sum($feeAmounts);

            $invoice = Invoice::create([
                'student_id' => $student->id,
                'customer_name' => $student->name,
                'total_amount' => $total,
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'paid_amount' => 0,
                'remaining_amount' => $total,
                'status' => Invoice::STATUS_UNPAID,
            ]);

            foreach ($gradeFees as $fee) {
                $invoice->fees()->attach($fee->id, [
                    'amount' => $feeAmounts[$fee->id] ?? 0,
                ]);
            }
        }
    }
}