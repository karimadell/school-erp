<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentRefundAllocation;
use App\Services\Finance\PaymentAllocationAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Finance V2, Phase 2A (docs/finance-v2-architecture.md) — «Финансы →
 * Поступления». A READ-ONLY accounting surface over canonical confirmed
 * student collections. Rooted at InvoicePayment (never Invoice, never
 * CashTransaction) — see the class-level note on InvoicePaymentService for
 * why InvoicePayment is the sole canonical source of confirmed student
 * money. This controller never creates, updates, or deletes any record.
 */
class FinanceCollectionsController extends Controller
{
    /** Canonical payment_method values InvoicePaymentService::record() accepts — same list, single source of truth for filter/display. */
    public const METHOD_LABELS = [
        'cash' => 'Наличные',
        'bank' => 'Банковский перевод',
        'card' => 'Банковская карта',
        'transfer' => 'Перевод',
        'instapay' => 'InstaPay',
    ];

    public function __construct()
    {
        $this->middleware('permission:view collections');
    }

    public function index(Request $request, PaymentAllocationAnalyzer $analyzer): View
    {
        $filters = [
            'date_from' => $request->date('date_from'),
            'date_to' => $request->date('date_to'),
            'payment_method' => $request->filled('payment_method') ? $request->string('payment_method')->toString() : null,
            'cash_account_id' => $request->integer('cash_account_id') ?: null,
            'student_id' => $request->integer('student_id') ?: null,
            'fee_id' => $request->integer('fee_id') ?: null,
        ];

        $base = $this->filteredQuery($filters);

        $payments = (clone $base)
            ->with([
                'invoice.student.class',
                'invoice.academicYear',
                'cashAccount',
                'creator',
                'allocations.item.fee',
                'allocations.refundAllocations',
                'refunds.allocations.allocation.item.fee',
            ])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Scoped by the exact same filters as the page, but NOT paginated —
        // page totals must reconcile to every filtered payment, not just
        // the current page (§8 of the Phase 2A spec).
        $filteredIds = (clone $base)->pluck('id');

        $rows = $payments->getCollection()->map(function (InvoicePayment $payment) use ($analyzer) {
            $refundAllocationsAgainstOwnAllocations = $payment->allocations->flatMap->refundAllocations;

            $status = $analyzer->classifyPayment($payment, $payment->allocations, $refundAllocationsAgainstOwnAllocations);

            $refundRows = $payment->refunds->map(function (PaymentRefund $refund) use ($analyzer, $payment) {
                $refundStatus = $analyzer->classifyRefund($refund, $refund->allocations, $payment->allocations->keyBy('id'));

                return [
                    'refund' => $refund,
                    'status' => $refundStatus,
                ];
            });

            $refunded = $payment->refunds->reduce(
                fn (string $carry, PaymentRefund $refund): string => bcadd($carry, (string) $refund->amount, 2),
                '0.00'
            );

            return [
                'payment' => $payment,
                'status' => $status,
                'gross' => (string) $payment->amount,
                'refunded' => $refunded,
                'net' => bcsub((string) $payment->amount, $refunded, 2),
                'refund_rows' => $refundRows,
            ];
        });
        $payments->setCollection($rows);

        return view('dashboard.finance.collections.index', [
            'payments' => $payments,
            'totals' => $this->totals($filteredIds, $filters['fee_id']),
            'cashAccounts' => CashAccount::query()->where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'fees' => Fee::query()->orderBy('category')->orderBy('name_ru')->get(['id', 'name_ru', 'category']),
            'methodLabels' => self::METHOD_LABELS,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  array{date_from: ?\Illuminate\Support\Carbon, date_to: ?\Illuminate\Support\Carbon, payment_method: ?string, cash_account_id: ?int, student_id: ?int, fee_id: ?int}  $filters
     */
    private function filteredQuery(array $filters)
    {
        // §9 — date range filters on InvoicePayment.paid_at, the actual
        // money-confirmation date, never Invoice due_date/created_at.
        return InvoicePayment::query()
            ->when($filters['date_from'], fn ($q) => $q->whereDate('paid_at', '>=', $filters['date_from']))
            ->when($filters['date_to'], fn ($q) => $q->whereDate('paid_at', '<=', $filters['date_to']))
            // §5 — payment method is read/filtered from InvoicePayment.payment_method,
            // never CashTransaction.payment_method (unreliable/NULL there).
            ->when($filters['payment_method'], fn ($q) => $q->where('payment_method', $filters['payment_method']))
            ->when($filters['cash_account_id'], fn ($q) => $q->where('cash_account_id', $filters['cash_account_id']))
            ->when($filters['student_id'], fn ($q) => $q->whereHas('invoice', fn ($iq) => $iq->where('student_id', $filters['student_id'])))
            // §9 — service filter is driven strictly through PaymentAllocation
            // → InvoiceItem → Fee. A matching payment still appears with its
            // FULL cash amount on its row; only the service-attributed totals
            // (see totals()) narrow to the matching portion. Never infers a
            // service for an unallocated payment.
            ->when($filters['fee_id'], fn ($q) => $q->whereHas('allocations.item', fn ($aq) => $aq->where('fee_id', $filters['fee_id'])));
    }

    /**
     * §8 — global cash totals vs. service-attribution totals vs. the
     * explicit unallocated gap between them. Never presents the two as
     * equal unless mathematically true.
     *
     * @param  Collection<int, int>  $paymentIds
     * @return array<string, string>
     */
    private function totals(Collection $paymentIds, ?int $feeId): array
    {
        $totalCollectedCash = $this->money((string) InvoicePayment::query()->whereIn('id', $paymentIds)->sum('amount'));
        $totalCashRefunds = $this->money((string) PaymentRefund::query()->whereIn('invoice_payment_id', $paymentIds)->sum('amount'));
        $netCashCollections = bcsub($totalCollectedCash, $totalCashRefunds, 2);

        $attributedAllocations = PaymentAllocation::query()
            ->whereIn('invoice_payment_id', $paymentIds)
            ->when($feeId, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('fee_id', $feeId)));

        $attributedAllocationIds = (clone $attributedAllocations)->pluck('id');
        $attributedCollections = $this->money((string) (clone $attributedAllocations)->sum('amount'));
        $attributedRefunds = $this->money((string) PaymentRefundAllocation::query()->whereIn('payment_allocation_id', $attributedAllocationIds)->sum('amount'));
        $netAttributedCollections = bcsub($attributedCollections, $attributedRefunds, 2);

        return [
            'total_collected_cash' => $totalCollectedCash,
            'total_cash_refunds' => $totalCashRefunds,
            'net_cash_collections' => $netCashCollections,
            'attributed_collections' => $attributedCollections,
            'attributed_refunds' => $attributedRefunds,
            'net_attributed_collections' => $netAttributedCollections,
            // Only a meaningful "grandfathered history" bucket when no
            // service filter narrows attribution — see the view for how
            // this is labeled once a service filter is active (it then
            // means "not attributed to the selected service", a distinct
            // quantity from the classic Phase 1E unallocated bucket).
            'unallocated_collections' => bcsub($totalCollectedCash, $attributedCollections, 2),
            'unallocated_refunds' => bcsub($totalCashRefunds, $attributedRefunds, 2),
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
