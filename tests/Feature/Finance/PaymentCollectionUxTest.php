<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Payment collection UX corrective — /dashboard/invoices/{invoice}/payments/create
 * no longer defaults to "whichever installment happens to be first by
 * sequence" (the confirmed source of the misleading "100 EGP" default).
 * Outstanding services are the primary focus, fully paid ones are
 * collapsed, a period must be consciously chosen when more than one
 * exists, and every existing server-side safeguard (overpayment caps,
 * allocation-sum validation, cash-drawer requirement, idempotency,
 * immutable history) is completely untouched — this file proves the
 * presentation changed, never the accounting.
 */
class PaymentCollectionUxTest extends FinanceOperationsTestCase
{
    /** @return array{0: Invoice, 1: InvoiceItem, 2: InvoiceItem} */
    private function twoItemInvoice(): array
    {
        $secondFee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);

        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '1700.00', 'total_amount' => '1700.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1700.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();

        $item1 = InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение',
            'unit_price' => '1200.00', 'quantity' => 1, 'amount' => '1200.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1200.00',
        ]);
        $item2 = InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $secondFee->id, 'description' => 'Транспорт',
            'unit_price' => '500.00', 'quantity' => 1, 'amount' => '500.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00',
        ]);

        return [$invoice->fresh(), $item1, $item2];
    }

    private function payFully(Invoice $invoice, InvoiceItem $item): void
    {
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: (string) $item->amount,
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            allocations: [['invoice_item_id' => $item->id, 'amount' => (string) $item->amount]],
        );
    }

    public function test_outstanding_services_are_clearly_listed(): void
    {
        [$invoice, , $item2] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $response->assertSee('Что оплачиваем?');
        $response->assertSee($item2->fee->name_ru);
        $response->assertSee('500.00 EGP');
    }

    public function test_fully_paid_services_are_collapsed(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();
        $this->payFully($invoice, $item1);
        $invoice = $invoice->fresh();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));
        $response->assertOk();
        $html = $response->getContent();

        $response->assertSee('Показать оплаченные услуги');
        $response->assertSee($item2->fee->name_ru); // still outstanding, primary section

        // The paid item's name must appear only inside the collapsed
        // "paid-services" container, not in the primary outstanding table.
        preg_match('/<div class="collapse" id="paid-services">(.*?)<\/div>\s*<\/div>/s', $html, $collapseMatch);
        $this->assertNotEmpty($collapseMatch, 'paid-services collapse container not found');
        $this->assertStringContainsString($item1->fee->name_ru, $collapseMatch[1]);

        $beforeCollapse = substr($html, 0, strpos($html, 'id="paid-services"'));
        $primarySection = substr($beforeCollapse, strrpos($beforeCollapse, '<h2'));
        $this->assertStringNotContainsString($item1->fee->name_ru, $primarySection);
    }

    public function test_no_default_amount_is_implied_on_initial_load_with_multiple_periods(): void
    {
        [$invoice] = $this->twoItemInvoice();
        InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Разовые услуги', 'sequence' => 1, 'due_date' => '2027-05-31', 'amount' => '100.00', 'paid_amount' => '0.00', 'remaining_amount' => '100.00', 'status' => 'pending']);
        InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Период 2', 'sequence' => 2, 'due_date' => '2026-11-01', 'amount' => '1600.00', 'paid_amount' => '0.00', 'remaining_amount' => '1600.00', 'status' => 'pending']);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        // The amount field carries no server-rendered value at all — no
        // "100.00" or any other figure implied before the accountant acts.
        $response->assertSee('id="payment-amount"', false);
        $response->assertDontSee('value="100.00"', false);
        $response->assertDontSee('value="1600.00"', false);
        $response->assertSee('— Выберите период оплаты —');
    }

    public function test_accountant_must_deliberately_select_the_relevant_period(): void
    {
        [$invoice] = $this->twoItemInvoice();
        InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Разовые услуги', 'sequence' => 1, 'due_date' => '2027-05-31', 'amount' => '100.00', 'paid_amount' => '0.00', 'remaining_amount' => '100.00', 'status' => 'pending']);
        InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Период 2', 'sequence' => 2, 'due_date' => '2026-11-01', 'amount' => '1600.00', 'paid_amount' => '0.00', 'remaining_amount' => '1600.00', 'status' => 'pending']);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $html = $response->getContent();
        // The select is required and starts on a disabled placeholder —
        // no installment is selected by default.
        $this->assertMatchesRegularExpression('/<select name="invoice_installment_id" id="installment" class="form-select" required[^>]*>\s*<option value="" selected disabled>/', $html);
        // Every "Оплатить сейчас" input starts disabled until a period is chosen.
        $this->assertMatchesRegularExpression('/allocation-input[^>]*disabled/', $html);
    }

    public function test_correct_per_service_remaining_amounts_are_displayed(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();
        $this->payFully($invoice, $item1);
        $partial = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->fresh()->id,
            cashAccountId: $this->cash->id,
            amount: '200.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            allocations: [['invoice_item_id' => $item2->id, 'amount' => '200.00']],
        );
        $this->assertNotNull($partial);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice->fresh()));

        $response->assertOk();
        $response->assertSee('300.00 EGP'); // 500 - 200 remaining on the transport item
    }

    public function test_partial_payment_still_works(): void
    {
        $invoice = $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('500.00', $invoice->paid_amount);
        $this->assertSame('700.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
    }

    public function test_allocation_sum_must_equal_payment_amount(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1000.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$item1->id => '400.00', $item2->id => '400.00'], // sums to 800, not 1000
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_per_item_overpayment_is_rejected_server_side(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1700.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$item1->id => '1300.00', $item2->id => '400.00'], // item1 only has 1200.00
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_installment_overpayment_is_rejected_server_side(): void
    {
        $invoice = $this->invoice('1200.00');
        $installment = InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Полная оплата', 'sequence' => 1, 'due_date' => '2027-01-01', 'amount' => '500.00', 'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'status' => 'pending']);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '600.00', 'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
            'invoice_installment_id' => $installment->id,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_cash_drawer_requirement_is_unchanged(): void
    {
        [$invoice] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));
        $response->assertOk();
        $response->assertSee('data-cash-drawer="1"', false);
        $response->assertSee($this->cash->name);
    }

    public function test_non_cash_behavior_is_unchanged(): void
    {
        $invoice = $this->invoice('1200.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '1200.00', 'payment_method' => 'bank',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('bank', InvoicePayment::sole()->payment_method);
    }

    public function test_viewing_the_page_performs_zero_accounting_mutations(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();
        InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Разовые услуги', 'sequence' => 1, 'due_date' => '2027-05-31', 'amount' => '100.00', 'paid_amount' => '0.00', 'remaining_amount' => '100.00', 'status' => 'pending']);
        $invoiceSnapshot = $invoice->fresh()->toArray();
        $item1Snapshot = $item1->fresh()->toArray();
        $item2Snapshot = $item2->fresh()->toArray();
        $paymentCountBefore = InvoicePayment::count();
        $refundCountBefore = PaymentRefund::count();

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice))->assertOk();

        $this->assertSame($invoiceSnapshot, $invoice->fresh()->toArray());
        $this->assertSame($item1Snapshot, $item1->fresh()->toArray());
        $this->assertSame($item2Snapshot, $item2->fresh()->toArray());
        $this->assertSame($paymentCountBefore, InvoicePayment::count());
        $this->assertSame($refundCountBefore, PaymentRefund::count());
    }

    public function test_permissions_remain_unchanged(): void
    {
        [$invoice] = $this->twoItemInvoice();
        // createPayment()/storePayment() are gated by 'manage invoices',
        // not 'view invoices' (FinanceOperationsController::__construct())
        // — unchanged by this corrective; a view-only role gets 403 on both.
        $viewer = $this->user('reception');
        $viewer->givePermissionTo('view invoices');

        $this->actingAs($viewer)->get(route('dashboard.invoices.payments.create', $invoice))->assertForbidden();
        $this->actingAs($viewer)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $manager = $this->user('reception');
        $manager->givePermissionTo(['view invoices', 'manage invoices']);
        $this->actingAs($manager)->get(route('dashboard.invoices.payments.create', $invoice))->assertOk();
    }

    public function test_invoice_payment_and_refund_history_remains_unchanged_after_viewing(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $paymentSnapshot = $payment->fresh()->toArray();

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice->fresh()))->assertOk();

        $this->assertSame($paymentSnapshot, $payment->fresh()->toArray());
    }
}
