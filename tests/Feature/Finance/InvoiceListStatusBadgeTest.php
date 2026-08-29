<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;

/**
 * Regression guard for the 2026-08-29 UAT bug: the invoices list page
 * (dashboard.invoices.index) mapped status via an if/elseif/@else chain
 * that only recognised 'paid' and 'unpaid'/'pending' — every other status,
 * 'partial' included, fell into a catch-all @else that unconditionally
 * rendered the "cancelled" label. The invoice detail page already used a
 * complete associative lookup and was never affected.
 */
class InvoiceListStatusBadgeTest extends FinanceOperationsTestCase
{
    private function invoiceWithStatus(string $status): Invoice
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['status' => $status])->save();

        return $invoice;
    }

    public function test_partial_invoice_displays_partial_label_and_not_cancelled(): void
    {
        $invoice = $this->invoiceWithStatus(Invoice::STATUS_PARTIAL);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.index'));

        $response->assertOk();
        $response->assertSee(__('invoices.partial'));
        $response->assertDontSee(__('invoices.cancelled'));
    }

    public function test_paid_invoice_displays_paid_label(): void
    {
        $invoice = $this->invoiceWithStatus(Invoice::STATUS_PAID);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.index'));

        $response->assertOk();
        $response->assertSee(__('invoices.paid'));
    }

    public function test_unpaid_invoice_displays_unpaid_label(): void
    {
        $invoice = $this->invoiceWithStatus(Invoice::STATUS_UNPAID);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.index'));

        $response->assertOk();
        $response->assertSee(__('invoices.unpaid'));
    }

    public function test_cancelled_invoice_displays_cancelled_label(): void
    {
        $invoice = $this->invoiceWithStatus(Invoice::STATUS_CANCELLED);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.index'));

        $response->assertOk();
        $response->assertSee(__('invoices.cancelled'));
    }

    public function test_partial_invoice_label_is_correct_in_every_supported_locale(): void
    {
        $invoice = $this->invoiceWithStatus(Invoice::STATUS_PARTIAL);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $response = $this->actingAs($this->accountant)->get(route('dashboard.invoices.index'));

            $response->assertOk();
            $response->assertSee(__('invoices.partial'));
            $response->assertDontSee(__('invoices.cancelled'));
        }
    }
}
