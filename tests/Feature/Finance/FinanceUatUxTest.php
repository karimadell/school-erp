<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;

class FinanceUatUxTest extends FinanceOperationsTestCase
{
    public function test_invoice_view_makes_purpose_and_private_pdf_sharing_discoverable(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Назначение платежа')
            ->assertSee('Обучение')
            ->assertSee('Скачать PDF')
            ->assertSee('Поделиться')
            ->assertSee(route('dashboard.invoices.pdf', $invoice), false);
    }

    public function test_invoice_create_action_is_prominent_and_permission_protected(): void
    {
        $viewer = $this->user('reception');
        $viewer->givePermissionTo('view invoices');

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk()
            ->assertSee('Выставить счёт')
            ->assertSee(route('dashboard.invoices.create'), false);

        $this->actingAs($viewer)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk()
            ->assertDontSee('Выставить счёт');

        $this->actingAs($viewer)
            ->get(route('dashboard.invoices.create'))
            ->assertForbidden();
    }

    public function test_cash_report_explains_automatic_cash_basis_and_totals_all_filtered_rows(): void
    {
        foreach (range(1, 25) as $index) {
            CashTransaction::create([
                'cash_account_id' => $this->cash->id,
                'type' => CashTransaction::TYPE_IN,
                'category' => CashTransaction::CATEGORY_INCOME,
                'amount' => '10.00',
                'description' => 'Test income '.$index,
            ]);
        }

        $reportUser = $this->user('admin');

        $this->actingAs($reportUser)
            ->get(route('dashboard.cash.reports'))
            ->assertOk()
            ->assertSee('Отчёт формируется автоматически')
            ->assertSee('250.00');
    }

    public function test_internal_transfer_is_visible_but_excluded_from_operating_totals(): void
    {
        $invoice = $this->invoice('500.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            paymentMethod: 'cash',
            amount: '500.00',
            idempotencyKey: '77777777-7777-4777-8777-777777777777',
            actor: $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '100.00',
            reason: 'Проверка кассового отчёта',
            idempotencyKey: '88888888-8888-4888-8888-888888888888',
            actor: $this->accountant,
        );
        Expense::create([
            'title' => 'Проверочный расход',
            'amount' => '200.00',
            'category' => 'operations',
            'expense_date' => today(),
            'cash_account_id' => $this->cash->id,
        ]);

        $this->cash->forceFill(['balance' => '2000.00'])->save();
        $destination = CashAccount::create([
            'name' => 'Вторая касса',
            'type' => CashAccount::TYPE_CASH,
            'balance' => '0.00',
            'is_active' => true,
        ]);
        $reportUser = $this->user('admin');
        $this->actingAs($reportUser)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $this->cash->id,
            'to_account_id' => $destination->id,
            'amount' => '1000.00',
            'purpose' => 'Внутреннее перемещение',
        ])->assertRedirect(route('dashboard.cash.transfers'));

        $this->assertDatabaseHas('cash_transactions', [
            'cash_account_id' => $this->cash->id,
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_TRANSFER,
            'amount' => '1000.00',
        ]);
        $this->assertDatabaseHas('cash_transactions', [
            'cash_account_id' => $destination->id,
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_TRANSFER,
            'amount' => '1000.00',
        ]);

        // Historical Main ERP transfers were saved without category because
        // the controller wrote an unfillable `method` attribute. Their exact
        // generated descriptions must remain excluded as well.
        CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'type' => CashTransaction::TYPE_OUT,
            'amount' => '1000.00',
            'description' => 'Transfer OUT #TR-20260818-legacy to Вторая касса',
        ]);
        CashTransaction::create([
            'cash_account_id' => $destination->id,
            'type' => CashTransaction::TYPE_IN,
            'amount' => '1000.00',
            'description' => 'Transfer IN #TR-20260818-legacy from Основная касса',
        ]);

        $response = $this->actingAs($reportUser)->get(route('dashboard.cash.reports'));
        $response->assertOk();
        $this->assertTrue($response->viewData('transactions')->getCollection()->contains(
            fn (CashTransaction $transaction) => $transaction->category === CashTransaction::CATEGORY_TRANSFER
        ));
        $this->assertEquals('500.00', $response->viewData('totalIn'));
        $this->assertEquals('300.00', $response->viewData('totalOut'));
        $this->assertEquals([500.0], $response->viewData('chartIn')->map(fn ($value) => (float) $value)->values()->all());
        $this->assertEquals([300.0], $response->viewData('chartOut')->map(fn ($value) => (float) $value)->values()->all());
    }
}
