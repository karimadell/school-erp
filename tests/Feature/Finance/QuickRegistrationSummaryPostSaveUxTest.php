<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Finance\CashSessionService;

/**
 * Single-page completion flow: Karim does not want Quick Registration to
 * redirect to a separate summary/payment/receipt page. A successful save
 * redirects back to the SAME quick-registration.create screen, which then
 * renders an inline success panel (in place of the form) exposing the
 * invoice, payment/receipt, and print/next-registration actions.
 */
class QuickRegistrationSummaryPostSaveUxTest extends QuickRegistrationUxTestCase
{
    public function test_a_successful_submission_redirects_back_to_the_same_create_screen_not_a_separate_page(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '250.00']],
            'payment_method' => 'cash',
        ]));

        // Same route as the form itself — never the separate summary page.
        $response->assertRedirect(route('dashboard.quick-registration.create'));
    }

    public function test_the_inline_success_panel_exposes_invoice_payment_and_print_actions_on_the_same_screen(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '250.00']],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();

        // Following the redirect lands on the very same create route/view —
        // the panel replaces the form there, no navigation to another page.
        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        // Phase 1 — Quick Registration document semantics: 250.00 was
        // collected against this fee's 1000.00 total, so this is a
        // partial payment — the header must say so, not falsely claim
        // the registration and payment are fully confirmed.
        $page->assertSee('Регистрация завершена. Принята частичная оплата.')
            ->assertSee($invoice->invoice_number)
            ->assertSee($payment->payment_number)
            ->assertSee($invoice->total_amount)
            ->assertSee($payment->amount)
            ->assertSee($invoice->remaining_amount)
            ->assertSee(route('dashboard.payments.receipt', $payment), false)
            ->assertSee(route('dashboard.invoices.print', $invoice), false)
            ->assertSee(route('dashboard.quick-registration.create'), false);

        // The employee is not looking at the ordinary intake form anymore.
        $page->assertDontSee('Подтвердить оплату и завершить регистрацию');
    }

    public function test_the_success_panel_only_appears_once_a_fresh_page_load_does_not_repeat_it(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '250.00']],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();
        // A second, independent visit ("Новая регистрация" already consumed
        // the one-shot flash) must show the ordinary empty intake form again.
        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee('Подтвердить оплату и завершить регистрацию')
            ->assertDontSee('Регистрация и оплата подтверждены');
    }
}
