<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\StudentBalanceService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentRefundTest extends FinanceOperationsTestCase
{
    private function refunds(): InvoiceRefundService
    {
        return app(InvoiceRefundService::class);
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: $amount,
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );
    }

    private function refund(InvoicePayment $payment, string $amount, string $reason = 'Возврат'): PaymentRefund
    {
        return $this->refunds()->refund(
            invoicePaymentId: $payment->id,
            amount: $amount,
            reason: $reason,
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );
    }

    public function test_full_refund_reverses_the_payment(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        $refund = $this->refund($payment, '1200.00');

        $this->assertInstanceOf(PaymentRefund::class, $refund);
        $this->assertNotNull($refund->refund_number);

        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->paid_amount);
        $this->assertSame('1200.00', (string) $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
    }

    public function test_partial_refund_updates_status_and_refundable(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        $this->refund($payment, '400.00');

        $invoice->refresh();
        $this->assertSame('800.00', (string) $invoice->paid_amount);
        $this->assertSame('400.00', (string) $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame('800.00', $payment->fresh()->refundableAmount());
    }

    public function test_refund_creates_outgoing_cash_movement(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');
        $balanceAfterPayment = (string) $this->cash->fresh()->balance; // 1200.00

        $refund = $this->refund($payment, '500.00');

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $refund->cash_transaction_id,
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_REFUND,
            'amount' => '500.00',
        ]);
        $this->assertSame('1200.00', $balanceAfterPayment);
        $this->assertSame('700.00', (string) $this->cash->fresh()->balance);
    }

    public function test_original_payment_remains_immutable(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        $this->refund($payment, '1200.00');

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('1200.00', (string) $fresh->amount);
        // No negative payment row was ever written.
        $this->assertSame(0, InvoicePayment::where('amount', '<', 0)->count());
        $this->assertSame(1, InvoicePayment::count());
    }

    public function test_refund_cannot_exceed_remaining_refundable(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');
        $this->refund($payment, '1000.00');

        $this->expectException(ValidationException::class);
        $this->refund($payment, '300.00'); // only 200 remains
    }

    public function test_duplicate_refund_with_same_key_is_idempotent(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');
        $key = (string) Str::uuid();

        $first = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'Возврат', idempotencyKey: $key, actor: $this->accountant);
        $second = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'Возврат', idempotencyKey: $key, actor: $this->accountant);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentRefund::count());
    }

    public function test_non_refundable_registration_fee_is_protected(): void
    {
        // Invoice whose only item is a non-refundable registration fee.
        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '500.00', 'total_amount' => '500.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Регистрационный взнос',
            'unit_price' => '500.00', 'quantity' => 1, 'amount' => '500.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'is_non_refundable' => true,
        ]);
        $payment = $this->pay($invoice, '500.00');

        $this->expectException(ValidationException::class);
        $this->refund($payment, '100.00');
    }

    public function test_refund_is_atomic_when_rejected(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        try {
            $this->refund($payment, '5000.00'); // exceeds refundable
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            // no partial record, no cash movement
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
        $this->assertSame('1200.00', (string) $this->cash->fresh()->balance);
    }

    // ----- Integration -----------------------------------------------------

    public function test_student_balance_is_correct_after_refund(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');
        $balance = app(StudentBalanceService::class);

        $this->assertSame(0.0, $balance->outstandingBalance($this->student->fresh()));

        $this->refund($payment, '1200.00');

        $this->assertSame(1200.0, $balance->outstandingBalance($this->student->fresh()));
    }

    // ----- Authorization ---------------------------------------------------

    public function test_unauthorized_user_cannot_refund(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($reception)
            ->post(route('dashboard.payments.refund.store', $payment), [
                'amount' => '100.00', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_accountant_can_refund_via_route(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->pay($invoice, '1200.00');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.payments.refund.store', $payment), [
                'amount' => '300.00', 'reason' => 'Перерасчёт', 'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame('900.00', (string) $invoice->fresh()->paid_amount);
    }
}
