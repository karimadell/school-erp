<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\PromiseToPay;
use App\Models\Student;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplication;
use App\Models\TariffAdjustment;
use Illuminate\Support\Collection;

class StudentFinanceSummaryService
{
    public function summarize(Student $student): array
    {
        $invoices = $student->invoices->sortByDesc('created_at')->values();
        $payments = $invoices->flatMap->payments
            ->sortByDesc(fn ($payment) => $payment->paid_at ?? $payment->created_at)
            ->values();
        $adjustments = TariffAdjustment::with(['fee', 'segments'])
            ->where('student_id', $student->id)->where('status', TariffAdjustment::STATUS_POSTED)
            ->latest('approved_at')->get();
        $promises = PromiseToPay::with(['invoice', 'payment'])
            ->where('student_id', $student->id)->latest()->get();
        $credits = StudentCredit::with(['sourceAdjustment', 'applications.invoice'])
            ->where('student_id', $student->id)->latest()->get();
        $applications = StudentCreditApplication::where('student_id', $student->id)->get();
        $appliedByInvoice = $applications->groupBy('invoice_id')->map(fn ($rows) => $this->sum($rows, 'amount'));
        $grossRemaining = $this->sum($invoices, 'remaining_amount');
        $creditApplied = $this->sum($applications, 'amount');
        $availableCredit = $this->sum($credits, 'available_amount');
        $netOutstanding = bcsub($grossRemaining, $creditApplied, 2);
        $overdueNet = $invoices->filter(fn (Invoice $invoice) => $invoice->due_date?->isPast()
                && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true))
            ->reduce(function (string $sum, Invoice $invoice) use ($appliedByInvoice): string {
                $net = bcsub((string) $invoice->remaining_amount, $appliedByInvoice->get($invoice->id, '0.00'), 2);

                return bcadd($sum, bccomp($net, '0.00', 2) > 0 ? $net : '0.00', 2);
            }, '0.00');

        return [
            'invoices' => $invoices,
            'payments' => $payments,
            'adjustments' => $adjustments,
            'promises' => $promises,
            'credits' => $credits,
            'credit_applications' => $applications,
            'gross_invoiced' => $this->sum($invoices, 'total_amount'),
            // paid_amount is maintained net of refunds by the canonical payment
            // and refund services. Summing raw payment rows would overstate cash
            // retained after a refund.
            'cash_paid' => $this->sum($invoices, 'paid_amount'),
            'gross_remaining' => $grossRemaining,
            'credit_applied' => $creditApplied,
            'available_credit' => $availableCredit,
            'net_outstanding' => $netOutstanding,
            'net_student_balance' => bcsub($availableCredit, $netOutstanding, 2),
            'promised' => $this->sum($promises->where('status', PromiseToPay::STATUS_OPEN), 'promised_amount'),
            'overdue_net' => $overdueNet,
            // Compatibility aliases for existing canonical consumers.
            'invoiced' => $this->sum($invoices, 'total_amount'),
            'paid' => $this->sum($invoices, 'paid_amount'),
            'remaining' => $netOutstanding,
            'overdue' => $overdueNet,
            'latest_payment' => $payments->first(),
        ];
    }

    private function sum(Collection $records, string $field): string
    {
        return $records->reduce(
            fn (string $sum, $record): string => bcadd($sum, (string) $record->{$field}, 2),
            '0.00',
        );
    }
}
