<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 1B.1 (docs/finance-v2-architecture.md §19 Phase 1B.1).
 *
 * InvoicePaymentService::record() used to determine "already paid" from a
 * raw gross InvoicePayment::sum('amount') query, which InvoiceRefundService
 * never adjusts (it writes a PaymentRefund + an outgoing CashTransaction,
 * never a negative InvoicePayment row). So an invoice paid in full and then
 * partially refunded still read as "fully paid" here, even though
 * Invoice::netPaidAmount()/refreshPaymentStatus() (used everywhere else —
 * refunds, Student Finance, the invoice's own persisted columns) correctly
 * showed it as outstanding again. This file proves the fix: record() now
 * reuses the same canonical Invoice::netPaidAmount() calculation.
 */
class FinanceV2Phase1B1RefundRepaymentTest extends FinanceOperationsTestCase
{
    public function test_repayment_after_partial_refund_succeeds_and_returns_invoice_to_fully_paid(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $balanceAfterPayment = $this->cash->fresh()->balance;

        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'audit fix verification',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $balanceAfterRefund = $this->cash->fresh()->balance;
        $this->assertSame(bcsub($balanceAfterPayment, '400.00', 2), $balanceAfterRefund);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
        $this->assertSame('400.00', (string) $invoice->fresh()->remaining_amount);

        $repayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertSame('400.00', (string) $repayment->amount);
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'exactly one new InvoicePayment beyond the original');

        $cashTx = CashTransaction::where('invoice_payment_id', $repayment->id)->sole();
        $this->assertSame('400.00', (string) $cashTx->amount);
        $this->assertSame(CashTransaction::TYPE_IN, $cashTx->type);
        $this->assertSame(bcadd($balanceAfterRefund, '400.00', 2), $this->cash->fresh()->balance, 'account balance increases exactly by the repayment');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->remaining_amount);
        $this->assertSame('1000.00', (string) $invoice->paid_amount);
    }

    public function test_full_refund_then_full_repayment_succeeds_exactly_once(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '1000.00', reason: 'full refund',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('1000.00', (string) $invoice->fresh()->remaining_amount);

        $repayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertSame('1000.00', (string) $repayment->amount);
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->remaining_amount);

        // Repaying again must now correctly reject — the invoice really is
        // fully paid this time.
        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
    }

    public function test_repaying_more_than_the_newly_outstanding_amount_after_partial_refund_is_rejected(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'partial refund',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // Only 400.00 is newly outstanding — 401.00 must still be rejected,
        // proving the fix does not open the door to real overpayment.
        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '401.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            );
            $this->fail('Expected a ValidationException for exceeding the newly outstanding amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(1, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'the rejected attempt wrote nothing');
    }

    public function test_overpayment_rejection_is_unchanged_when_no_refund_exists(): void
    {
        $invoice = $this->invoice('1000.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            );
            $this->fail('Expected a ValidationException — the invoice is already fully paid with no refund.');
        } catch (ValidationException $e) {
            $this->assertSame('Счёт уже полностью оплачен.', $e->errors()['amount'][0]);
        }

        $this->assertSame(1, InvoicePayment::where('invoice_id', $invoice->id)->count());
    }

    public function test_repayment_idempotency_key_replay_does_not_double_record(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'idempotency check',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $key = (string) Str::uuid();
        $first = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
        );
        $second = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'original payment + one repayment, replay created nothing extra');
        $this->assertSame(1, CashTransaction::where('invoice_payment_id', $first->id)->count());
    }

    public function test_student_finance_view_reflects_net_balance_after_refund_and_repayment(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'student finance check',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('600.00 EGP')->assertSee('400.00 EGP');

        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('1000.00 EGP')->assertSee('0.00 EGP');
    }
}
