<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Unified Cashier Workspace (PR C1) — a READ-ONLY presentation layer over
 * the existing, unchanged accounting data (Invoice/InvoiceItem/
 * PaymentAllocation, via InvoicePaymentService's own already-canonical
 * isAllocationClean()/remainingAllocatableByItem()). This class owns no
 * accounting logic of its own: it only decides how to GROUP an existing
 * Student+AcademicYear's outstanding invoices into operator-facing,
 * service-level rows, hiding installment/allocation identity the operator
 * never needs to see.
 *
 * Every row's own 'remaining' figure is advisory/display-only — the exact
 * same invariant the pre-existing FinanceOperationsController::
 * createPayment() page already relies on. FinanceCollectionService (via
 * InvoicePaymentService::record()) always re-resolves and re-validates the
 * true remaining capacity, under its own row lock, at submission time;
 * nothing here is ever trusted for money.
 *
 * Scope note: uses remainingAllocatableByItem()'s whole-invoice figure,
 * not the finer remainingByItemPerInstallment() a shared-calendar-
 * installment invoice would need for a byte-perfect per-item display cap
 * (see that method's own docblock — UAT #25). Deliberately not pulled in
 * here: the server-side re-validation this view model already defers to
 * makes that extra precision a display-quality concern, not a money-
 * safety one, and pulling it in here would start to make this class a
 * second accounting engine, which it must not become.
 */
class StudentObligationsViewModel
{
    public function __construct(private InvoicePaymentService $payments)
    {
    }

    /**
     * @return Collection<int, array{
     *     invoice_id: int, invoice_item_id: ?int, label: string,
     *     charged: string, remaining: string, overdue: bool, ambiguous: bool,
     * }>
     */
    public function forStudentYear(Student $student, AcademicYear $year): Collection
    {
        $invoices = Invoice::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->outstanding()
            ->with('items.fee')
            ->get();

        return $invoices
            ->flatMap(fn (Invoice $invoice) => $this->rowsForInvoice($invoice))
            ->filter(fn (array $row) => bccomp($row['remaining'], '0.00', 2) > 0)
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rowsForInvoice(Invoice $invoice): Collection
    {
        $items = $invoice->items;
        $overdue = (bool) ($invoice->due_date?->isPast()
            && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true));

        // Scope boundary (verified, not assumed): InvoicePaymentService::
        // record() can only auto-resolve WHICH installment a payment
        // settles when at most one is still outstanding — with two or
        // more, an explicit invoice_installment_id is required (a
        // multi-installment "walk sequentially" capability like
        // MixedPaymentCollectionOrchestrator::collectAcrossInstallments()
        // uses for NEW charges is deliberately not something
        // FinanceCollectionService exposes for EXISTING obligations, and
        // extending it is out of scope for this PR). A multi-installment
        // existing obligation is therefore shown for awareness only, not
        // offered for item-level submission here — the pre-existing
        // single-invoice payment page (already handling this case
        // correctly via its own installment/coverage-period UI) remains
        // the right place to collect against it.
        $outstandingInstallments = $invoice->installments()->where('remaining_amount', '>', 0)->count();
        if ($outstandingInstallments > 1) {
            return collect([$this->row($invoice, null, (string) $invoice->remaining_amount, $overdue, true, false)]);
        }

        // Single-item invoice: no allocation ambiguity is even possible —
        // the invoice's own already-maintained remaining_amount trivially
        // IS that one item's own remaining (Invoice::refreshPaymentStatus()
        // keeps it correct on every record()/refund()), so this needs
        // neither isAllocationClean() nor an extra query.
        if ($items->count() === 1) {
            return collect([$this->row($invoice, $items->first(), (string) $invoice->remaining_amount, $overdue, false, true)]);
        }

        // Multi-item, allocation-clean: one row per item, each capped at
        // its own true net-remaining capacity.
        if ($this->payments->isAllocationClean($invoice)) {
            $remainingByItem = $this->payments->remainingAllocatableByItem($invoice);

            return $items->map(fn (InvoiceItem $item) => $this->row(
                $invoice, $item, (string) ($remainingByItem->get($item->id) ?? '0.00'), $overdue, false, true,
            ));
        }

        // Allocation-ambiguous multi-item invoice: true per-item capacity
        // is not knowable (see InvoicePaymentService::isAllocationClean()'s
        // own docblock) — one honest whole-invoice row instead of a
        // fabricated per-service split. Still submittable (record() itself
        // accepts an unallocated payment here, exactly like the classic
        // payment page already does for the same case) as long as there is
        // at most one outstanding installment (checked above).
        return collect([$this->row($invoice, null, (string) $invoice->remaining_amount, $overdue, true, true)]);
    }

    private function row(Invoice $invoice, ?InvoiceItem $item, string $remaining, bool $overdue, bool $ambiguous, bool $submittable): array
    {
        return [
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item?->id,
            'label' => $ambiguous
                ? "Счёт {$invoice->display_number}"
                : ($item?->fee?->name_ru ?? $item?->description ?? 'Услуга'),
            'charged' => (string) ($item?->amount ?? $invoice->total_amount),
            'remaining' => bcadd($remaining, '0', 2),
            'overdue' => $overdue,
            'ambiguous' => $ambiguous,
            // False only for a multi-installment invoice — see the scope
            // boundary above. Still shown (for awareness/overdue totals),
            // but the workspace must not offer a receive-now input for it.
            'submittable' => $submittable,
        ];
    }
}
