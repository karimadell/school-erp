<?php

namespace App\Services\Finance;

use App\Exceptions\DuplicateOpenInvoiceException;
use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — cashier "charge & collect".
 *
 * Composes the two canonical services (issue an invoice, then record a
 * payment) into a single atomic action so a front-desk cashier can charge a
 * student for a not-yet-invoiced service and take the money in one controlled
 * step — the safe replacement for the disabled orphan-cash path. No new
 * pricing, numbering, or balance logic is introduced here.
 *
 * The whole operation runs in one transaction: if collection fails (e.g.
 * over-payment, inactive cash account) the freshly issued invoice is rolled
 * back too, so there is never an orphan invoice or orphan cash movement.
 *
 * @phpstan-type ChargeResult array{invoice: \App\Models\Invoice, payment: ?InvoicePayment}
 */
class ChargeAndCollectService
{
    public function __construct(
        private InvoiceIssuanceService $issuer,
        private InvoicePaymentService $payments,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  Validated charge-and-collect data.
     * @return array{invoice: \App\Models\Invoice, payment: ?InvoicePayment}
     */
    public function chargeAndCollect(Student $student, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($student, $data, $actor, $ip, $userAgent) {
            // 0) Narrow duplicate-open-invoice guard: refuse to issue a second
            //    collectible invoice for the same student + academic year +
            //    service. The cashier should collect against the existing
            //    unpaid/partially-paid invoice instead. Void/cancelled and
            //    fully-paid invoices never block. Registration-fee uniqueness
            //    remains a separate, independent check inside issuance.
            $this->guardAgainstDuplicateOpenInvoice($student, $data);

            // 1) Issue the invoice through the canonical path (tariff pricing,
            //    numbering, items, installment, registration-fee protection,
            //    audit) — unchanged behaviour.
            $invoice = $this->issuer->issue($student, $data, $actor, $ip, $userAgent);

            // 2) Optionally collect payment against the just-issued invoice.
            //    A zero amount means "charge only" (issue without collecting).
            $payment = null;
            $collect = bcadd((string) ($data['collect_amount'] ?? '0'), '0', 2);
            if (bccomp($collect, '0.00', 2) > 0) {
                $payment = $this->payments->record(
                    invoiceId: $invoice->id,
                    cashAccountId: CashAccount::resolvePaymentAccountId((string) $data['payment_method'], isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null),
                    amount: $collect,
                    paymentMethod: (string) $data['payment_method'],
                    idempotencyKey: (string) $data['idempotency_key'],
                    actor: $actor,
                    reference: 'Оплата при начислении '.$invoice->display_number,
                );
            }

            return ['invoice' => $invoice->fresh(), 'payment' => $payment];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicateOpenInvoiceException
     */
    private function guardAgainstDuplicateOpenInvoice(Student $student, array $data): void
    {
        $feeId = (int) collect($data['items'] ?? [])->pluck('fee_id')->first();
        if ($feeId <= 0) {
            return;
        }

        $existing = Invoice::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', (int) $data['academic_year_id'])
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->whereHas('items', fn ($query) => $query->where('fee_id', $feeId))
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            throw new DuplicateOpenInvoiceException(
                $existing->id,
                (string) $existing->display_number,
                'По этой услуге уже есть неоплаченный счёт. Примите оплату по существующему счёту.',
            );
        }
    }
}
