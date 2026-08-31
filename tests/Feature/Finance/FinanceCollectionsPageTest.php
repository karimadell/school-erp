<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\StudentCredit;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\StudentCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance V2, Phase 2A (docs/finance-v2-architecture.md) — «Финансы →
 * Поступления». Full coverage of the read-only Collections page:
 * FinanceCollectionsController + dashboard.finance.collections.index.blade.php.
 *
 * The page is rooted at InvoicePayment; nothing here writes a payment or
 * refund through the page itself — every fixture is created via the
 * existing canonical services/direct model writes, exactly like the rest of
 * the Finance V2 suite.
 */
class FinanceCollectionsPageTest extends FinanceOperationsTestCase
{
    private function payments(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function refunds(): InvoiceRefundService
    {
        return app(InvoiceRefundService::class);
    }

    private function bank(): CashAccount
    {
        return CashAccount::create(['name' => 'Банковский счёт', 'type' => CashAccount::TYPE_BANK, 'balance' => '0.00', 'is_active' => true]);
    }

    private function instapay(): CashAccount
    {
        return CashAccount::create(['name' => 'InstaPay', 'type' => CashAccount::TYPE_INSTAPAY, 'balance' => '0.00', 'is_active' => true]);
    }

    private function secondInvoiceItem(Invoice $invoice, string $amount, ?int $feeId = null): InvoiceItem
    {
        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $feeId ?? $this->fee->id,
            'description' => 'Вторая строка (Phase 2A page test)',
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
        ]);
    }

    // ----- 11-16: methods appear, method read from InvoicePayment ----------

    public function test_cash_payment_appears(): void
    {
        $invoice = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('Наличные');
    }

    public function test_instapay_payment_appears(): void
    {
        $invoice = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->instapay()->id, amount: '100.00', paymentMethod: 'instapay', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('InstaPay');
    }

    public function test_bank_payment_appears(): void
    {
        $invoice = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->bank()->id, amount: '100.00', paymentMethod: 'bank', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('Банковский перевод');
    }

    public function test_card_payment_appears(): void
    {
        $invoice = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->bank()->id, amount: '100.00', paymentMethod: 'card', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('Банковская карта');
    }

    public function test_transfer_method_payment_appears(): void
    {
        $invoice = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->bank()->id, amount: '100.00', paymentMethod: 'transfer', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('Перевод');
    }

    public function test_payment_method_is_read_from_invoice_payment_not_cash_transaction(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->instapay()->id, amount: '100.00', paymentMethod: 'instapay', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        // The audit's own confirmed finding: canonical CashTransaction rows
        // never populate payment_method at all.
        $this->assertNull($payment->cashTransaction->payment_method);
        $this->assertSame('instapay', $payment->payment_method);

        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))
            ->assertOk()->assertSee('InstaPay');
    }

    // ----- 17-19: service display / refund netting -------------------------

    public function test_multi_service_payment_displays_exact_service_allocations(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '500.00'],
            ],
        );

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('2 услуги');
        $row = $response->viewData('payments')->getCollection()->first();
        $this->assertCount(2, $row['payment']->allocations);
        $this->assertSame('1000.00', (string) $row['payment']->allocations->firstWhere('invoice_item_id', $tuition->id)->amount);
        $this->assertSame('500.00', (string) $row['payment']->allocations->firstWhere('invoice_item_id', $uniform->id)->amount);
    }

    public function test_fully_attributed_refund_calculates_row_net_correctly(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '300.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $row = $response->viewData('payments')->getCollection()->first();

        $this->assertSame('1000.00', $row['gross']);
        $this->assertSame('300.00', $row['refunded']);
        $this->assertSame('700.00', $row['net']);
    }

    public function test_multiple_refunds_net_correctly(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '200.00', reason: 'a', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '150.00', reason: 'b', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $row = $response->viewData('payments')->getCollection()->first();

        $this->assertSame('350.00', $row['refunded']);
        $this->assertSame('650.00', $row['net']);
        $this->assertCount(2, $row['refund_rows']);
    }

    // ----- 20-22: unallocated / needs-review display ------------------------

    public function test_historical_unallocated_payment_shows_ne_raspredeleno(): void
    {
        $invoice = $this->invoice('1000.00');
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))
            ->assertOk()->assertSee('Не распределено');
    }

    public function test_historical_unallocated_refund_handled_honestly(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $refund = \App\Models\PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Legacy unattributed refund (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $row = $response->viewData('payments')->getCollection()->first();
        $refundRow = $row['refund_rows']->firstWhere('refund.id', $refund->id);

        $this->assertSame(\App\Services\Finance\PaymentAllocationStatus::Unallocated, $refundRow['status']);
    }

    public function test_corrupt_partial_state_shows_trebuet_proverki(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        \App\Models\PaymentAllocation::create(['invoice_payment_id' => $payment->id, 'invoice_item_id' => $tuition->id, 'amount' => '600.00']);

        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))
            ->assertOk()->assertSee('Требует проверки');
    }

    // ----- 23-25: exclusions by construction --------------------------------

    public function test_internal_transfer_cash_transaction_does_not_appear(): void
    {
        $owner = CashAccount::owner() ?? CashAccount::create(['name' => 'Владелец', 'type' => CashAccount::TYPE_OWNER_CASH, 'role' => CashAccount::ROLE_OWNER, 'balance' => '0.00', 'is_active' => true]);
        $this->cash->forceFill(['balance' => '1000.00'])->save();
        app(\App\Services\Finance\CashTransferService::class)->transfer(
            fromAccountId: $this->cash->id, toAccountId: $owner->id, amount: '500.00',
            purpose: 'Передача наличных владельцу', notes: null, actor: $this->accountant,
            transferType: \App\Models\CashTransfer::TYPE_HANDOVER,
        );

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $this->assertSame(0, $response->viewData('payments')->total());
        $this->assertSame('0.00', $response->viewData('totals')['total_collected_cash']);
    }

    public function test_expense_cash_transaction_does_not_appear(): void
    {
        Expense::create([
            'cash_account_id' => $this->cash->id, 'amount' => '250.00', 'category' => 'general',
            'title' => 'Test expense', 'description' => 'Test expense', 'expense_date' => now(),
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $this->assertSame(0, $response->viewData('payments')->total());
    }

    public function test_student_credit_does_not_appear_as_collection(): void
    {
        $invoice = $this->invoice('1000.00');
        $item = $invoice->items->first();

        // Minimal valid fixture chain for StudentCredit's required
        // source_adjustment_id FK — same fixture shape as
        // PaymentAllocationTest::test_student_credit_application_does_not_create_payment_allocation().
        $feePrice = \App\Models\FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id,
            'amount' => '1000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date,
            'is_active' => true,
        ]);
        $coverageId = DB::table('service_coverages')->insertGetId([
            'student_id' => $this->student->id, 'fee_id' => $this->fee->id, 'invoice_item_id' => $item->id,
            'fee_price_id' => $feePrice->id, 'coverage_start' => now()->toDateString(), 'coverage_end' => now()->addYear()->toDateString(),
            'billing_unit' => 'monthly', 'original_unit_price' => '1000.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adjustmentId = DB::table('tariff_adjustments')->insertGetId([
            'student_id' => $this->student->id, 'fee_id' => $this->fee->id, 'service_coverage_id' => $coverageId,
            'new_fee_price_id' => $feePrice->id, 'status' => 'posted', 'kind' => 'credit',
            'total_difference' => '200.00', 'currency' => 'EGP',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $credit = StudentCredit::create([
            'student_id' => $this->student->id,
            'source_adjustment_id' => $adjustmentId,
            'original_amount' => '200.00', 'consumed_amount' => '0.00', 'available_amount' => '200.00',
            'status' => StudentCredit::STATUS_AVAILABLE,
        ]);
        app(StudentCreditService::class)->apply($credit, $invoice, '200.00', (string) Str::uuid(), $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $this->assertSame(0, $response->viewData('payments')->total());
        $this->assertSame('0.00', $response->viewData('totals')['total_collected_cash']);
    }

    // ----- 26-32: filters -----------------------------------------------------

    public function test_cash_account_filter_works(): void
    {
        $bank = $this->bank();
        $invoiceA = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $invoiceB = $this->invoice('200.00');
        $this->payments()->record(invoiceId: $invoiceB->id, cashAccountId: $bank->id, amount: '200.00', paymentMethod: 'bank', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['cash_account_id' => $bank->id]));

        $this->assertSame(1, $response->viewData('payments')->total());
        $this->assertSame('200.00', $response->viewData('payments')->getCollection()->first()['gross']);
    }

    public function test_payment_method_filter_works(): void
    {
        $invoiceA = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $invoiceB = $this->invoice('200.00');
        $this->payments()->record(invoiceId: $invoiceB->id, cashAccountId: $this->instapay()->id, amount: '200.00', paymentMethod: 'instapay', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['payment_method' => 'instapay']));

        $this->assertSame(1, $response->viewData('payments')->total());
        $this->assertSame('instapay', $response->viewData('payments')->getCollection()->first()['payment']->payment_method);
    }

    public function test_student_filter_works(): void
    {
        $invoiceA = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $otherStudent = \App\Models\Student::create(['last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'phone' => '+201009998877', 'class_id' => $this->student->class_id, 'status' => 'registration_completed']);
        $otherInvoice = Invoice::create(['student_id' => $otherStudent->id, 'academic_year_id' => $this->year->id, 'customer_name' => $otherStudent->full_name, 'currency' => 'EGP', 'subtotal_amount' => '300.00', 'total_amount' => '300.00', 'discount_amount' => '0.00', 'paid_amount' => '0.00', 'remaining_amount' => '300.00', 'status' => 'unpaid', 'due_date' => '2027-01-01', 'created_by' => $this->accountant->id]);
        $otherInvoice->invoice_number = Invoice::numberFor($otherInvoice->id, '2026');
        $otherInvoice->save();
        InvoiceItem::create(['invoice_id' => $otherInvoice->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение', 'unit_price' => '300.00', 'quantity' => 1, 'amount' => '300.00', 'paid_amount' => '0.00', 'remaining_amount' => '300.00']);
        $this->payments()->record(invoiceId: $otherInvoice->id, cashAccountId: $this->cash->id, amount: '300.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['student_id' => $otherStudent->id]));

        $this->assertSame(1, $response->viewData('payments')->total());
        $this->assertSame('300.00', $response->viewData('payments')->getCollection()->first()['gross']);
    }

    public function test_date_range_uses_paid_at(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $payment->forceFill(['paid_at' => now()->subDays(10)])->saveQuietly();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['date_from' => now()->subDays(1)->toDateString()]));

        $this->assertSame(0, $response->viewData('payments')->total(), 'a payment paid 10 days ago is excluded from a from-yesterday filter');
    }

    public function test_created_at_and_invoice_due_date_do_not_control_collection_date(): void
    {
        // Invoice created "long ago" (due_date far in the past) but the
        // payment itself paid_at today — must still appear under a
        // today-only filter, proving the filter is not keyed off the
        // invoice's own dates.
        $invoice = $this->invoice('100.00', dueDate: now()->subYear()->toDateString());
        $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]));

        $this->assertSame(1, $response->viewData('payments')->total());
    }

    public function test_service_filter_only_matches_attributed_service(): void
    {
        $uniformFee = Fee::create(['name_ru' => 'Форма', 'category' => 'uniform', 'amount' => '1.00', 'is_active' => true]);
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00', $uniformFee->id);
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '500.00'],
            ],
        );

        // A second, unrelated invoice with no uniform at all.
        $tuitionOnlyInvoice = $this->invoice('300.00');
        $this->payments()->record(invoiceId: $tuitionOnlyInvoice->id, cashAccountId: $this->cash->id, amount: '300.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['fee_id' => $uniformFee->id]));

        $this->assertSame(1, $response->viewData('payments')->total(), 'only the payment touching Форма matches');
    }

    public function test_row_cash_amount_stays_full_payment_amount_under_a_service_filter(): void
    {
        $uniformFee = Fee::create(['name_ru' => 'Форма', 'category' => 'uniform', 'amount' => '1.00', 'is_active' => true]);
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00', $uniformFee->id);
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '500.00'],
            ],
        );

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['fee_id' => $uniformFee->id]));
        $row = $response->viewData('payments')->getCollection()->first();

        // §9 — the ROW shows the FULL 1500.00 payment amount, not the 500.00
        // uniform-only slice, even though the filter matched on uniform.
        $this->assertSame('1500.00', $row['gross']);
    }

    public function test_unallocated_historical_payments_are_not_assigned_to_a_service_filter(): void
    {
        $uniformFee = Fee::create(['name_ru' => 'Форма', 'category' => 'uniform', 'amount' => '1.00', 'is_active' => true]);
        $invoice = $this->invoice('1000.00');
        $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        // Historical unallocated payment — never touches any Fee via an allocation.
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index', ['fee_id' => $uniformFee->id]));

        $this->assertSame(0, $response->viewData('payments')->total(), 'an unallocated payment is never guessed into a service filter match');
    }

    // ----- 33-40: totals -------------------------------------------------------

    public function test_gross_cash_total_correct(): void
    {
        $invoiceA = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $invoiceB = $this->invoice('250.00');
        $this->payments()->record(invoiceId: $invoiceB->id, cashAccountId: $this->cash->id, amount: '250.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('350.00', $totals['total_collected_cash']);
    }

    public function test_gross_cash_total_includes_legitimate_historical_unallocated_payments(): void
    {
        $invoice = $this->invoice('1000.00');
        $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('1500.00', $totals['total_collected_cash']);
    }

    public function test_attributed_collection_total_excludes_unallocated_amount(): void
    {
        $invoice = $this->invoice('1000.00');
        $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('0.00', $totals['attributed_collections']);
        $this->assertSame('1500.00', $totals['unallocated_collections']);
    }

    public function test_cash_refund_total_includes_legitimate_historical_unallocated_refunds(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        \App\Models\PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Legacy unattributed refund (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('100.00', $totals['total_cash_refunds']);
    }

    public function test_attributed_refund_total_excludes_unattributed_refund(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        \App\Models\PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Legacy unattributed refund (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('0.00', $totals['attributed_refunds']);
        $this->assertSame('100.00', $totals['unallocated_refunds']);
    }

    public function test_net_cash_collection_formula_correct(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '250.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('1000.00', $totals['total_collected_cash']);
        $this->assertSame('250.00', $totals['total_cash_refunds']);
        $this->assertSame('750.00', $totals['net_cash_collections']);
    }

    public function test_net_attributed_service_formula_correct(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '250.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $totals = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->viewData('totals');

        $this->assertSame('1000.00', $totals['attributed_collections']);
        $this->assertSame('250.00', $totals['attributed_refunds']);
        $this->assertSame('750.00', $totals['net_attributed_collections']);
        // Fully attributed here — net cash and net attributed agree exactly.
        $this->assertSame($totals['net_cash_collections'], $totals['net_attributed_collections']);
    }

    public function test_unallocated_gap_displayed_explicitly(): void
    {
        $invoice = $this->invoice('1000.00');
        $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk()->assertSee('Не распределено (не подтверждено по услугам)');
        $totals = $response->viewData('totals');
        $this->assertNotSame($totals['net_cash_collections'], $totals['net_attributed_collections'], 'the page never implies the two are equal when they are not');
    }

    // ----- 41-44: no double-counting, receipt links, refund status ----------

    public function test_no_double_counting_invoice_payment_plus_cash_transaction(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '200.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        // One row per InvoicePayment — the refund's own CashTransaction
        // never becomes a second top-level Collections row.
        $this->assertSame(1, $response->viewData('payments')->total());
    }

    public function test_receipt_link_points_to_correct_pay_record(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))
            ->assertOk()->assertSee(route('dashboard.payments.receipt', $payment), false);
    }

    public function test_partial_refund_status_display_correct(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '300.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $row = $response->viewData('payments')->getCollection()->first();

        $this->assertSame('700.00', $row['net']);
        $this->assertNotSame('0.00', $row['refunded']);
        $this->assertNotSame($row['gross'], $row['net']);
    }

    public function test_full_refund_status_display_correct(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '1000.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $row = $response->viewData('payments')->getCollection()->first();

        $this->assertSame('1000.00', $row['refunded']);
        $this->assertSame('0.00', $row['net']);
    }

    // ----- 45-46: authorization -----------------------------------------------

    public function test_unauthorized_user_cannot_access_page(): void
    {
        $this->actingAs($this->user('reception'))->get(route('dashboard.finance.collections.index'))->assertForbidden();
        $this->actingAs($this->user('teacher'))->get(route('dashboard.finance.collections.index'))->assertRedirect('/login');
    }

    public function test_authorized_roles_can_access(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'))->assertOk();
        $this->actingAs($this->user('cashier'))->get(route('dashboard.finance.collections.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('dashboard.finance.collections.index'))->assertOk();
        $this->actingAs($this->user('school-admin'))->get(route('dashboard.finance.collections.index'))->assertOk();
        $this->actingAs($this->user('principal'))->get(route('dashboard.finance.collections.index'))->assertOk();
    }

    // ----- 47: N+1 characterization --------------------------------------------

    public function test_query_count_does_not_grow_proportionally_with_row_count(): void
    {
        $invoiceA = $this->invoice('100.00');
        $this->payments()->record(invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '100.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        DB::enableQueryLog();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $countForOneRow = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Fixture creation must NOT be counted — only the page's own request.
        for ($i = 0; $i < 4; $i++) {
            $invoice = $this->invoice((string) (100 + $i).'.00');
            $this->payments()->record(invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) (100 + $i).'.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        }

        DB::enableQueryLog();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));
        $countForFiveRows = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $countForOneRow + 3,
            $countForFiveRows,
            "query count grew from {$countForOneRow} (1 row) to {$countForFiveRows} (5 rows) — eager loading should keep this roughly flat, not proportional to row count"
        );
    }
}
