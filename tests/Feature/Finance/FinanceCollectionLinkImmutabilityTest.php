<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Support\Str;

/**
 * Unified Collection foundation (PR B corrective pass, P1 fix) —
 * Invoice.finance_collection_id / InvoicePayment.finance_collection_id are
 * historical accounting linkage, not a freely-reassignable tag. Direct
 * tests of the four transitions Invoice::booted()/InvoicePayment::booted()
 * now guard, independent of FinanceCollectionService itself (a generic
 * ->update() call from any code path must be protected, not just the
 * service's own single legitimate linking call site).
 */
class FinanceCollectionLinkImmutabilityTest extends FinanceOperationsTestCase
{
    private function issueInvoice(): \App\Models\Invoice
    {
        $fee = Fee::create(['name_ru' => 'X', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '100.00', 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    private function collection(): FinanceCollection
    {
        return FinanceCollection::create([
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'a'),
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'status' => FinanceCollection::STATUS_COMPLETED,
        ]);
    }

    // ----- Invoice.finance_collection_id -----

    public function test_invoice_null_to_collection_id_is_allowed(): void
    {
        $invoice = $this->issueInvoice();
        $collection = $this->collection();

        $invoice->update(['finance_collection_id' => $collection->id]);

        $this->assertSame($collection->id, $invoice->fresh()->finance_collection_id);
    }

    public function test_invoice_same_collection_id_to_same_id_is_harmless(): void
    {
        $invoice = $this->issueInvoice();
        $collection = $this->collection();
        $invoice->update(['finance_collection_id' => $collection->id]);

        $invoice->update(['finance_collection_id' => $collection->id]);

        $this->assertSame($collection->id, $invoice->fresh()->finance_collection_id);
    }

    public function test_invoice_collection_id_cannot_be_reassigned_to_a_different_collection(): void
    {
        $invoice = $this->issueInvoice();
        $collectionA = $this->collection();
        $collectionB = $this->collection();
        $invoice->update(['finance_collection_id' => $collectionA->id]);

        $this->expectException(\LogicException::class);
        $invoice->update(['finance_collection_id' => $collectionB->id]);
    }

    public function test_invoice_collection_id_cannot_be_detached_to_null(): void
    {
        $invoice = $this->issueInvoice();
        $collection = $this->collection();
        $invoice->update(['finance_collection_id' => $collection->id]);

        $this->expectException(\LogicException::class);
        $invoice->update(['finance_collection_id' => null]);
    }

    // ----- InvoicePayment.finance_collection_id -----

    public function test_invoice_payment_null_to_collection_id_is_allowed(): void
    {
        $invoice = $this->issueInvoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '50.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $collection = $this->collection();

        $payment->update(['finance_collection_id' => $collection->id]);

        $this->assertSame($collection->id, $payment->fresh()->finance_collection_id);
    }

    public function test_invoice_payment_same_collection_id_to_same_id_is_harmless(): void
    {
        $invoice = $this->issueInvoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '50.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $collection = $this->collection();
        $payment->update(['finance_collection_id' => $collection->id]);

        $payment->update(['finance_collection_id' => $collection->id]);

        $this->assertSame($collection->id, $payment->fresh()->finance_collection_id);
    }

    public function test_invoice_payment_collection_id_cannot_be_reassigned_to_a_different_collection(): void
    {
        $invoice = $this->issueInvoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '50.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $collectionA = $this->collection();
        $collectionB = $this->collection();
        $payment->update(['finance_collection_id' => $collectionA->id]);

        $this->expectException(\LogicException::class);
        $payment->update(['finance_collection_id' => $collectionB->id]);
    }

    public function test_invoice_payment_collection_id_cannot_be_detached_to_null(): void
    {
        $invoice = $this->issueInvoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '50.00', paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $collection = $this->collection();
        $payment->update(['finance_collection_id' => $collection->id]);

        $this->expectException(\LogicException::class);
        $payment->update(['finance_collection_id' => null]);
    }

    // ----- FinanceCollectionService's own legitimate linking still works -----

    public function test_finance_collection_service_can_still_perform_its_legitimate_initial_linking(): void
    {
        $invoice = $this->issueInvoice();

        $collection = app(\App\Services\Finance\FinanceCollectionService::class)->collect([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => '50.00']],
        ], $this->accountant);

        $payment = $collection->invoicePayments->sole();
        $this->assertSame($collection->id, $payment->finance_collection_id);
    }
}
