<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Collection;

class StudentFinanceSummaryService
{
    public function summarize(Student $student): array
    {
        $invoices = $student->invoices->sortByDesc('created_at')->values();
        $payments = $invoices->flatMap->payments
            ->sortByDesc(fn ($payment) => $payment->paid_at ?? $payment->created_at)
            ->values();

        return [
            'invoices' => $invoices,
            'payments' => $payments,
            'invoiced' => $this->sum($invoices, 'total_amount'),
            'paid' => $this->sum($payments, 'amount'),
            'remaining' => $this->sum($invoices, 'remaining_amount'),
            'overdue' => $this->sum($invoices->filter(fn (Invoice $invoice) =>
                bccomp((string) $invoice->remaining_amount, '0.00', 2) > 0
                && $invoice->due_date?->isPast()
                && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true)
            ), 'remaining_amount'),
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
