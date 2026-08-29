<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Services\Finance\CashSessionService;

/**
 * Finance V2, Phase 1A — Quick Registration's existing per-line paid_now
 * already deterministically maps to a real InvoiceItem (matched by fee_id,
 * per the dadea35 fix), so this is the one caller wired to explicit
 * PaymentAllocation rows in Phase 1A. No proportional inference — every
 * amount here is copied from data Quick Registration already computed.
 */
class QuickRegistrationPaymentAllocationTest extends QuickRegistrationUxTestCase
{
    public function test_multi_line_paid_now_produces_the_exact_expected_allocation_rows(): void
    {
        $structure = $this->structure();
        $registrationFee = $this->fee('Регистрационный взнос', \App\Models\Fee::CATEGORY_REGISTRATION);
        $booksFee = $this->fee('Книги', \App\Models\Fee::CATEGORY_BOOKS);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registrationFee, [
            'services' => [
                ['fee_id' => $registrationFee->id, 'quantity' => 1, 'paid_now' => '700.00'],
                ['fee_id' => $booksFee->id, 'quantity' => 1, 'paid_now' => '250.00'],
            ],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();
        $this->assertSame('950.00', (string) $payment->amount);

        $registrationItem = $invoice->items()->where('fee_id', $registrationFee->id)->sole();
        $booksItem = $invoice->items()->where('fee_id', $booksFee->id)->sole();

        $this->assertSame(2, PaymentAllocation::count());
        $this->assertSame('700.00', (string) PaymentAllocation::where('invoice_item_id', $registrationItem->id)->sole()->amount);
        $this->assertSame('250.00', (string) PaymentAllocation::where('invoice_item_id', $booksItem->id)->sole()->amount);
        $this->assertSame($payment->id, PaymentAllocation::where('invoice_item_id', $registrationItem->id)->sole()->invoice_payment_id);
    }

    public function test_a_zero_paid_line_among_multiple_services_is_not_allocated(): void
    {
        $structure = $this->structure();
        $registrationFee = $this->fee('Регистрационный взнос', \App\Models\Fee::CATEGORY_REGISTRATION);
        $booksFee = $this->fee('Книги', \App\Models\Fee::CATEGORY_BOOKS);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registrationFee, [
            'services' => [
                ['fee_id' => $registrationFee->id, 'quantity' => 1, 'paid_now' => '400.00'],
                ['fee_id' => $booksFee->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $registrationItem = $invoice->items()->where('fee_id', $registrationFee->id)->sole();

        // Only the paid line gets an allocation; the zero-paid line has
        // nothing to allocate and correctly gets no row at all.
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($registrationItem->id, PaymentAllocation::sole()->invoice_item_id);
        $this->assertSame('400.00', (string) PaymentAllocation::sole()->amount);
    }

    public function test_zero_payment_creates_no_invoice_payment_and_therefore_no_allocation(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::sole()->status);
    }
}
