<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Finance\CashSessionService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 1 — Quick Registration document semantics: the success panel
 * header must never claim a payment was confirmed unless one actually
 * was. Replaces the old always-on "Регистрация и оплата подтверждены"
 * text with three states keyed on $invoice->status.
 */
class QuickRegistrationSuccessPanelStatusTest extends QuickRegistrationUxTestCase
{
    private function submit(string $paidNow): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => $paidNow]],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();
    }

    public function test_unpaid_state_shows_no_payment_text_and_hides_print_receipt(): void
    {
        $this->submit('0.00');
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $page->assertSee('Регистрация завершена. Оплата не производилась.')
            ->assertDontSee('Регистрация и оплата подтверждены')
            ->assertDontSee('Принята частичная оплата')
            ->assertDontSee('Печать квитанции')
            ->assertSee(route('dashboard.invoices.print', $invoice), false);
    }

    public function test_partial_state_shows_partial_text_and_both_print_actions(): void
    {
        $this->submit('250.00');
        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $page->assertSee('Регистрация завершена. Принята частичная оплата.')
            ->assertDontSee('Регистрация и оплата подтверждены')
            ->assertDontSee('Оплата не производилась')
            ->assertSee(route('dashboard.payments.receipt', $payment), false)
            ->assertSee(route('dashboard.invoices.print', $invoice), false);
    }

    public function test_paid_state_shows_full_payment_text_and_both_print_actions(): void
    {
        $this->submit('1000.00');
        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $page->assertSee('Регистрация и оплата подтверждены.')
            ->assertDontSee('Оплата не производилась')
            ->assertDontSee('Принята частичная оплата')
            ->assertSee(route('dashboard.payments.receipt', $payment), false)
            ->assertSee(route('dashboard.invoices.print', $invoice), false);
    }

    /** @return array<string, array{string, string, string}> */
    public static function localeProvider(): array
    {
        return [
            'ru unpaid' => ['ru', '0.00', 'Регистрация завершена. Оплата не производилась.'],
            'en unpaid' => ['en', '0.00', 'Registration completed. No payment collected.'],
            'ar unpaid' => ['ar', '0.00', 'تم التسجيل. لم يتم تحصيل أي دفعة.'],
            'ru partial' => ['ru', '250.00', 'Регистрация завершена. Принята частичная оплата.'],
            'en partial' => ['en', '250.00', 'Registration completed. Partial payment received.'],
            'ar partial' => ['ar', '250.00', 'تم التسجيل. تم استلام دفعة جزئية.'],
            'ru paid' => ['ru', '1000.00', 'Регистрация и оплата подтверждены.'],
            'en paid' => ['en', '1000.00', 'Registration and payment confirmed.'],
            'ar paid' => ['ar', '1000.00', 'تم تأكيد التسجيل والدفع.'],
        ];
    }

    #[DataProvider('localeProvider')]
    public function test_success_header_is_correctly_translated_per_locale(string $locale, string $paidNow, string $expectedText): void
    {
        $this->submit($paidNow);

        // SetLocale middleware resolves the active locale from the
        // session, not from a bare app()->setLocale() call, so the
        // session key must be set before the request runs.
        $this->withSession(['locale' => $locale])
            ->actingAs($this->accountant)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee($expectedText);
    }
}
