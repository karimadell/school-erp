<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\Invoice;
use App\Services\Finance\CashSessionService;

/**
 * Phase B coverage for the new-student Quick Registration success page,
 * which keeps its own registration-completion messaging but must still
 * expose the same print/PDF/profile/next-payment actions rather than
 * leaving a new-student employee to hunt for them.
 */
class QuickRegistrationSummaryPostSaveUxTest extends QuickRegistrationUxTestCase
{
    public function test_summary_page_exposes_print_pdf_profile_and_next_payment_links(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '250.00']],
            'payment_method' => 'cash',
        ]));

        $invoice = Invoice::sole();
        $response->assertRedirect(route('dashboard.quick-registration.summary', $invoice));

        $this->actingAs($this->accountant)
            ->get(route('dashboard.quick-registration.summary', $invoice))
            ->assertOk()
            ->assertSee(route('dashboard.invoices.print', $invoice), false)
            ->assertSee(route('dashboard.invoices.pdf', $invoice), false)
            ->assertSee(route('dashboard.invoices.show', $invoice), false)
            ->assertSee(route('dashboard.students.show', $invoice->student), false)
            ->assertSee(route('dashboard.invoices.payments.create', $invoice), false);
    }
}
