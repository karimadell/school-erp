<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\FinanceCollection;
use App\Models\SchoolSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\View\View;

/**
 * Unified Cashier Workspace (PR C2) — read-only receipt/reprint for a
 * completed FinanceCollection. Presentation only: reconstructs everything
 * shown from already-persisted canonical records reachable from the
 * FinanceCollection itself (its own linked InvoicePayment/Invoice rows) —
 * never from session/flash state, and this controller computes, allocates,
 * or prices nothing itself; FinanceCollectionService remains the sole
 * write engine for this domain (see that service's own docblock).
 *
 * Deliberately a separate controller from UnifiedCollectionController —
 * that one owns the write path (collect()); this one is a pure GET/read
 * document view, mirroring FinanceOperationsController's own existing
 * split between its write actions and its receipt()/receiptPdf() actions.
 */
class FinanceCollectionReceiptController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view invoices');
    }

    public function show(FinanceCollection $financeCollection): View
    {
        return view('dashboard.finance.unified-collection.receipt', $this->receiptData($financeCollection));
    }

    public function pdf(FinanceCollection $financeCollection)
    {
        $data = $this->receiptData($financeCollection);

        return Pdf::loadView('dashboard.finance.unified-collection.receipt-pdf', $data)
            ->setPaper('a4')->download(($financeCollection->collection_number ?: 'collection').'.pdf');
    }

    /**
     * Completed-only guard — mirrors RevenueEntryController::receipt()'s
     * own abort_if($revenueEntry->isDraft(), 404) precedent: a
     * FinanceCollection reaching this action while still 'pending' (its
     * own outer transaction mid-flight, never actually observable by a
     * separate request under FinanceCollectionService's current atomic
     * design) or a future 'failed' row is not a real receipt yet, so it
     * fails closed rather than rendering a half-formed document.
     */
    private function receiptData(FinanceCollection $financeCollection): array
    {
        abort_unless($financeCollection->status === FinanceCollection::STATUS_COMPLETED, 404);

        $financeCollection->load([
            'student',
            'academicYear',
            'creator',
            'cashAccount',
            'invoicePayments' => fn ($query) => $query->orderBy('id'),
            'invoicePayments.cashAccount',
            'invoicePayments.cashTransaction',
            'invoicePayments.creator',
            'invoicePayments.invoice',
            'invoicePayments.allocations.item.fee',
            'invoicePayments.refunds',
        ]);

        return [
            'collection' => $financeCollection,
            'payments' => $financeCollection->invoicePayments,
            // FinanceCollection::linkedInvoices() (PR B, §10 of that design)
            // is the honest "every invoice this collection touched at all"
            // view — invoices it issued, unioned with invoices it merely
            // recorded a payment against. Deliberately not $financeCollection
            // ->invoices() alone, which would silently omit every
            // pre-existing obligation this collection paid down.
            'linkedInvoices' => $financeCollection->linkedInvoices(),
            'grossTotal' => $financeCollection->grossReceivedTotal(),
            'settings' => SchoolSetting::current(),
            'methodLabels' => [
                'cash' => 'Наличные',
                'card' => 'Банковская карта',
                'bank' => 'Банковский перевод',
                'instapay' => 'InstaPay',
            ],
        ];
    }
}
