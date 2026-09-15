<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\PromiseToPay;
use App\Models\Student;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplication;
use App\Models\TariffAdjustment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StudentFinanceSummaryService
{
    public function summarize(Student $student): array
    {
        return $this->summarizeMany(new EloquentCollection([$student]))->get($student->id);
    }

    /**
     * Finance Workspace corrective PR #4 — the SAME canonical calculate()
     * every other summary uses, partitioned per academic year instead of
     * flattened across all of them. Every input collection is anchored to
     * a year through Invoice.academic_year_id (the only Finance record
     * that carries the column directly):
     *
     * - invoices: their own academic_year_id.
     * - adjustments: their postingInvoice's year (a STATUS_POSTED
     *   adjustment always has one — that's what "posted" means here).
     * - promises / credit applications: their linked invoice's year, when
     *   they have one; an unattached promise (invoice_id is nullable —
     *   see the "Без привязки к счёту" option on the promise form) belongs
     *   to no single year and is intentionally left out of every
     *   per-year bucket (it still appears in the flat, all-time summarize()
     *   this method never replaces).
     * - StudentCredit is a student-level wallet, never invoice- or
     *   year-anchored, and is deliberately NOT partitioned — passing an
     *   empty credits collection into each year's calculate() call is
     *   correct, not a gap: available_credit/net_student_balance are
     *   flat-only concepts, sourced from summarize() where a caller needs
     *   them, never duplicated here.
     *
     * @return array{years: Collection<int, AcademicYear>, byYear: array<int, array<string, mixed>>, defaultYearId: ?int}
     */
    public function summarizeByYear(Student $student): array
    {
        $student->loadMissing(['invoices.payments', 'enrollments']);

        $invoicesByYear = $student->invoices->groupBy('academic_year_id');
        $yearIds = $student->invoices->pluck('academic_year_id')
            ->merge($student->enrollments->pluck('academic_year_id'))
            ->filter()
            ->unique()
            ->values();

        $years = AcademicYear::whereIn('id', $yearIds)->orderByDesc('start_date')->get();

        $studentId = $student->id;
        $adjustments = TariffAdjustment::with(['fee', 'segments', 'postingInvoice'])
            ->where('student_id', $studentId)
            ->where('status', TariffAdjustment::STATUS_POSTED)
            ->latest('approved_at')
            ->get();
        $promises = PromiseToPay::with(['invoice', 'payment'])
            ->where('student_id', $studentId)
            ->latest()
            ->get();
        $applications = StudentCreditApplication::with('invoice')
            ->where('student_id', $studentId)
            ->get();

        $byYear = [];
        foreach ($years as $year) {
            $byYear[$year->id] = $this->calculate(
                $invoicesByYear->get($year->id, collect()),
                $adjustments->filter(fn (TariffAdjustment $adjustment) => $adjustment->postingInvoice?->academic_year_id === $year->id)->values(),
                $promises->filter(fn (PromiseToPay $promise) => $promise->invoice?->academic_year_id === $year->id)->values(),
                collect(),
                $applications->filter(fn (StudentCreditApplication $application) => $application->invoice?->academic_year_id === $year->id)->values(),
            );
        }

        $defaultYearId = $student->currentEnrollment?->academic_year_id;
        if ($defaultYearId === null || ! $years->contains('id', $defaultYearId)) {
            $defaultYearId = $years->first()?->id;
        }

        return ['years' => $years, 'byYear' => $byYear, 'defaultYearId' => $defaultYearId];
    }

    /**
     * Build the same canonical summary for several students with a bounded
     * number of Phase 2 queries. Results are keyed by student ID.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, array<string, mixed>>
     */
    public function summarizeMany(Collection $students): Collection
    {
        if ($students->isEmpty()) {
            return collect();
        }

        $students = new EloquentCollection($students->values()->all());
        $students->loadMissing('invoices.payments');
        $studentIds = $students->modelKeys();

        $adjustments = TariffAdjustment::with(['fee', 'segments'])
            ->whereIn('student_id', $studentIds)
            ->where('status', TariffAdjustment::STATUS_POSTED)
            ->latest('approved_at')
            ->get()
            ->groupBy('student_id');
        $promises = PromiseToPay::with(['invoice', 'payment'])
            ->whereIn('student_id', $studentIds)
            ->latest()
            ->get()
            ->groupBy('student_id');
        $credits = StudentCredit::with(['sourceAdjustment', 'applications.invoice'])
            ->whereIn('student_id', $studentIds)
            ->latest()
            ->get()
            ->groupBy('student_id');
        $applications = StudentCreditApplication::whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        return $students->mapWithKeys(fn (Student $student): array => [
            $student->id => $this->calculate(
                $student->invoices,
                $adjustments->get($student->id, collect()),
                $promises->get($student->id, collect()),
                $credits->get($student->id, collect()),
                $applications->get($student->id, collect()),
            ),
        ]);
    }

    private function calculate(
        Collection $studentInvoices,
        Collection $adjustments,
        Collection $promises,
        Collection $credits,
        Collection $applications,
    ): array {
        $invoices = $studentInvoices->sortByDesc('created_at')->values();
        $payments = $invoices->flatMap->payments
            ->sortByDesc(fn ($payment) => $payment->paid_at ?? $payment->created_at)
            ->values();
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
