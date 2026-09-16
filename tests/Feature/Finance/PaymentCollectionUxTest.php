<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\ServiceCoverage;
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

    /**
     * Two services, each with exactly one ServiceCoverage/InstallmentCoveragePeriod
     * of its own, under two DIFFERENT installments — real, trigger-validated
     * coverage data (not raw installments with no coverage at all), so the
     * service-first view treats both as genuine single-period calendar
     * services rather than once-bucket items.
     *
     * @return array{0: Invoice, 1: InvoiceItem, 2: InvoiceInstallment, 3: InvoiceItem, 4: InvoiceInstallment}
     */
    private function twoServiceTwoInstallmentInvoice(): array
    {
        $tuitionFee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuitionPrice = FeePrice::create(['fee_id' => $tuitionFee->id, 'academic_year_id' => $this->year->id, 'amount' => '2000.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'monthly', 'is_active' => true]);
        $transportFee = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transportPrice = FeePrice::create(['fee_id' => $transportFee->id, 'academic_year_id' => $this->year->id, 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'monthly', 'is_active' => true]);

        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '3500.00', 'total_amount' => '3500.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '3500.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();

        $tuitionItem = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $tuitionFee->id, 'description' => 'Обучение', 'unit_price' => '2000.00', 'quantity' => 1, 'amount' => '2000.00', 'paid_amount' => '0.00', 'remaining_amount' => '2000.00']);
        $transportItem = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $transportFee->id, 'description' => 'Трансфер', 'unit_price' => '1500.00', 'quantity' => 1, 'amount' => '1500.00', 'paid_amount' => '0.00', 'remaining_amount' => '1500.00']);

        $tuitionInstallment = InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Период 1', 'sequence' => 1, 'due_date' => '2026-10-01', 'amount' => '2000.00', 'paid_amount' => '0.00', 'remaining_amount' => '2000.00', 'status' => 'pending']);
        $transportInstallment = InvoiceInstallment::create(['invoice_id' => $invoice->id, 'name_ru' => 'Период 1', 'sequence' => 2, 'due_date' => '2026-10-01', 'amount' => '1500.00', 'paid_amount' => '0.00', 'remaining_amount' => '1500.00', 'status' => 'pending']);

        $tuitionCoverage = ServiceCoverage::create(['student_id' => $this->student->id, 'fee_id' => $tuitionFee->id, 'invoice_item_id' => $tuitionItem->id, 'fee_price_id' => $tuitionPrice->id, 'coverage_start' => '2026-10-01', 'coverage_end' => '2026-10-31', 'billing_unit' => 'monthly', 'original_unit_price' => '2000.00']);
        InstallmentCoveragePeriod::create(['invoice_installment_id' => $tuitionInstallment->id, 'service_coverage_id' => $tuitionCoverage->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '2000.00']);

        $transportCoverage = ServiceCoverage::create(['student_id' => $this->student->id, 'fee_id' => $transportFee->id, 'invoice_item_id' => $transportItem->id, 'fee_price_id' => $transportPrice->id, 'coverage_start' => '2026-10-01', 'coverage_end' => '2026-10-31', 'billing_unit' => 'monthly', 'original_unit_price' => '1500.00']);
        InstallmentCoveragePeriod::create(['invoice_installment_id' => $transportInstallment->id, 'service_coverage_id' => $transportCoverage->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '1500.00']);

        return [$invoice->fresh(), $tuitionItem, $tuitionInstallment, $transportItem, $transportInstallment];
    }

    public function test_outstanding_services_are_clearly_listed(): void
    {
        [$invoice, , $item2] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $response->assertSee('Что оплачивает родитель?');
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
        [$invoice] = $this->twoServiceTwoInstallmentInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        // The readonly total mirror and the hidden installment field both
        // carry no server-rendered value at all — no figure and no
        // installment is implied before the accountant acts.
        $response->assertSee('id="payment-amount"', false);
        $response->assertDontSee('value="2000.00"', false);
        $response->assertDontSee('value="1500.00"', false);
        $response->assertSee('id="installment-id-field" value=""', false);
    }

    public function test_accountant_must_deliberately_select_the_relevant_period(): void
    {
        [$invoice] = $this->twoServiceTwoInstallmentInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $html = $response->getContent();
        // No global installment-first dropdown exists any more.
        $this->assertStringNotContainsString('<select name="invoice_installment_id"', $html);
        // Every "Оплатить сейчас" service input starts enabled at its own
        // correct capacity (each of these two services has exactly one
        // period, so there is nothing ambiguous to force a radio choice
        // for) — but nothing is locked to either installment yet, so both
        // remain simultaneously available until the accountant acts.
        $this->assertMatchesRegularExpression('/max="2000\.00"[^>]*service-input/', $html);
        $this->assertMatchesRegularExpression('/max="1500\.00"[^>]*service-input/', $html);
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

    public function test_submit_button_is_disabled_until_a_positive_amount_is_entered(): void
    {
        [$invoice] = $this->twoItemInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/id="submit-payment-btn" disabled>Принять оплату/', $response->getContent());
    }

    public function test_services_on_different_installments_carry_different_installment_ids(): void
    {
        [$invoice, $tuitionItem, $tuitionInstallment, $transportItem, $transportInstallment] = $this->twoServiceTwoInstallmentInvoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertNotSame($tuitionInstallment->id, $transportInstallment->id);
        // Each service's own input is tagged with its OWN, correct,
        // DIFFERENT installment id — this is the data the client-side lock
        // relies on to make combining them in one submission impossible;
        // never a shared/global selection the accountant could misapply.
        $this->assertMatchesRegularExpression('/data-item-id="'.$tuitionItem->id.'"\s+data-installment-id="'.$tuitionInstallment->id.'"/', $html);
        $this->assertMatchesRegularExpression('/data-item-id="'.$transportItem->id.'"\s+data-installment-id="'.$transportInstallment->id.'"/', $html);
    }

    public function test_ambiguous_invoice_uses_the_safe_fallback_not_service_first(): void
    {
        [$invoice, $item1, $item2] = $this->twoItemInvoice();
        $legacyPayment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '300.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', 'legacy-'.Str::random()),
        ]);
        \App\Models\CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $legacyPayment->id, 'amount' => '300.00',
            'type' => \App\Models\CashTransaction::TYPE_IN, 'category' => \App\Models\CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.payments.create', $invoice->fresh()));

        $response->assertOk();
        // A historical unattributed payment makes the invoice unsafe for a
        // per-service breakdown — never guessed, the existing invoice-level
        // fallback (unchanged from before this pass) is used instead.
        $response->assertDontSee('Что оплачивает родитель?');
        $response->assertSee('Состав счёта');
        $response->assertSee('исторические платежи без полного распределения');
    }
}
