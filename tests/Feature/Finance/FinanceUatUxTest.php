<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\FinanceMonthlyReport;
use App\Filament\Pages\FinancialReport;
use App\Filament\Pages\StudentFinance;
use App\Filament\Pages\StudentFinanceReport;
use App\Filament\Pages\StudentLedger;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Fees\FeeResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Support\FinanceShareRecipient;

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

    public function test_employee_finance_navigation_uses_dashboard_routes(): void
    {
        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.finance.workspace'));

        $response->assertOk()
            ->assertSee(route('dashboard.finance.services.index'), false)
            ->assertSee(route('dashboard.finance.tariffs.index'), false)
            ->assertSee(route('dashboard.invoices.index'), false)
            ->assertSee(route('filament.admin.resources.expenses.index'), false)
            ->assertDontSee(route('dashboard.cash.expenses'), false)
            ->assertSee(route('dashboard.cash.reports'), false)
            ->assertSee(route('dashboard.finance.workspace'), false);
    }

    public function test_whatsapp_uses_primary_representative_without_exposing_private_pdf_url(): void
    {
        $this->student->representatives()->create([
            'relationship_type' => 'father',
            'full_name' => 'Основной контакт',
            'phone' => '010 1234 5678',
            'is_primary_contact' => true,
        ]);
        $invoice = $this->invoice();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.show', $invoice));
        $response->assertOk()->assertSee(__('finance_uat.send_whatsapp'));
        $html = $response->getContent();
        $this->assertStringContainsString('https://wa.me/201012345678', $html);
        $this->assertStringNotContainsString(route('dashboard.invoices.pdf', $invoice), urldecode($this->whatsappHref($html)));
    }

    public function test_whatsapp_falls_back_to_disabled_no_phone_state(): void
    {
        $this->student->update(['phone' => null]);
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.show', $invoice))
            ->assertOk()
            ->assertSee(__('finance_uat.phone_missing'))
            ->assertDontSee('wa.me/', false);
    }

    public function test_receipt_sharing_uses_text_only_whatsapp_and_safe_pdf_share(): void
    {
        $this->student->representatives()->create([
            'relationship_type' => 'mother',
            'full_name' => 'Основной контакт',
            'phone' => '+20 100 111 2233',
            'is_primary_contact' => true,
        ]);
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            paymentMethod: 'cash',
            amount: '100.00',
            idempotencyKey: '99999999-9999-4999-8999-999999999999',
            actor: $this->accountant,
        );

        $response = $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt', $payment));
        $response->assertOk()
            ->assertSee(__('finance_uat.share'))
            ->assertSee(__('finance_uat.send_whatsapp'));
        $html = $response->getContent();
        $this->assertStringContainsString('https://wa.me/201001112233', $html);
        $this->assertStringNotContainsString(route('dashboard.payments.receipt.pdf', $payment), urldecode($this->whatsappHref($html)));
    }

    public function test_phone_normalization_rejects_invalid_numbers(): void
    {
        $this->assertSame('201012345678', FinanceShareRecipient::normalize('010 1234 5678'));
        $this->assertSame('201001112233', FinanceShareRecipient::normalize('+20 100 111 2233'));
        $this->assertNull(FinanceShareRecipient::normalize('123'));
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
        $invoice = $this->invoice('1200.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            paymentMethod: 'cash',
            amount: '500.00',
            idempotencyKey: '77777777-7777-4777-8777-777777777777',
            actor: $this->accountant,
        );
        $this->assertSame('partial', $invoice->fresh()->status);
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
        // Cash Operations Phase 1: the destination leg of a transfer now
        // also requires an open shift when it's a cash-drawer account.
        $this->openCashSession($destination, $reportUser);
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

    public function test_obsolete_filament_finance_navigation_is_hidden_but_tools_remain_registered(): void
    {
        foreach ([FinancialReport::class, FinanceMonthlyReport::class, StudentLedger::class, StudentFinance::class, StudentFinanceReport::class] as $page) {
            $this->assertFalse($page::shouldRegisterNavigation());
        }
        foreach ([FeeResource::class, InvoiceResource::class, ExpenseResource::class] as $resource) {
            $this->assertFalse($resource::shouldRegisterNavigation());
        }
    }

    private function whatsappHref(string $html): string
    {
        preg_match('/href="([^"]*wa\.me[^"]*)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '');
    }
}
