<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Support\Str;

/**
 * Phase 2 — cashier "charge & collect".
 *
 * Proves the single atomic action that issues a charge for a not-yet-invoiced
 * service and optionally takes the money in one controlled step: the safe
 * replacement for the disabled orphan-cash path. Covers the permission gate,
 * the charge-only path, the charge-and-collect path, collection validation,
 * and the all-or-nothing rollback guarantee.
 */
class ChargeAndCollectTest extends FinanceOperationsTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'academic_year_id' => $this->year->id,
            'fee_id' => $this->fee->id,
            'quantity' => 1,
            'payment_period' => 'yearly',
            'due_date' => '2027-01-01',
            'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '0',
        ], $overrides);
    }

    public function test_charge_form_renders_for_authorized_user(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.charge.create', $this->student))
            ->assertOk()
            ->assertSee('Обучение')
            ->assertSee('Начислить и принять оплату');
    }

    public function test_form_requires_manage_invoices_permission(): void
    {
        $stranger = $this->user('reception'); // valid dashboard user, lacks 'manage invoices'

        $this->actingAs($stranger)
            ->get(route('dashboard.students.charge.create', $this->student))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload())
            ->assertForbidden();
    }

    public function test_charge_only_issues_unpaid_invoice_without_payment(): void
    {
        $response = $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'collect_amount' => '0',
            ]));

        $invoice = Invoice::sole();
        $response->assertRedirect(route('dashboard.invoices.show', $invoice));

        $this->assertSame('1200.00', $invoice->total_amount);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame('0.00', $this->money($this->cash->fresh()->balance));
    }

    public function test_charge_and_collect_issues_invoice_and_records_payment_atomically(): void
    {
        $response = $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'collect_amount' => '1200.00',
                'payment_method' => 'cash',
                'cash_account_id' => $this->cash->id,
            ]));

        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();

        $response->assertRedirect(route('dashboard.payments.receipt', $payment));
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame('1200.00', $invoice->fresh()->paid_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('1200.00', $this->money($this->cash->fresh()->balance));
    }

    public function test_collecting_requires_method_and_cash_account(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'collect_amount' => '1200.00',
                // payment_method + cash_account_id intentionally omitted
            ]))
            ->assertSessionHasErrors(['payment_method', 'cash_account_id']);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_over_payment_rolls_back_the_whole_operation(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'collect_amount' => '5000.00', // exceeds the 1200.00 charge
                'payment_method' => 'cash',
                'cash_account_id' => $this->cash->id,
            ]))
            ->assertSessionHasErrors('amount');

        // No orphan invoice, no orphan payment, no cash movement.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame('0.00', $this->money($this->cash->fresh()->balance));
    }

    // ----- Duplicate-open-invoice guard ----------------------------------

    public function test_existing_unpaid_same_service_invoice_blocks_charge(): void
    {
        $existing = $this->invoice(); // unpaid invoice for $this->fee, same student + year

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'collect_amount' => '1200.00',
                'payment_method' => 'cash',
                'cash_account_id' => $this->cash->id,
            ]))
            ->assertSessionHasErrors('fee_id')
            ->assertSessionHas('existing_invoice_id', $existing->id);

        // No second invoice, no payment, no cash movement.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame('0.00', $this->money($this->cash->fresh()->balance));
    }

    public function test_existing_partial_same_service_invoice_blocks_charge(): void
    {
        $existing = $this->invoice();
        $existing->forceFill([
            'status' => Invoice::STATUS_PARTIAL,
            'paid_amount' => '200.00',
            'remaining_amount' => '1000.00',
        ])->save();

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload())
            ->assertSessionHasErrors('fee_id')
            ->assertSessionHas('existing_invoice_id', $existing->id);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_fully_paid_same_service_invoice_does_not_block(): void
    {
        $paid = $this->invoice();
        $paid->forceFill([
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => '1200.00',
            'remaining_amount' => '0.00',
        ])->save();

        $response = $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload());

        $response->assertSessionHasNoErrors();

        // A legitimate new charge is issued alongside the settled one.
        $this->assertSame(2, Invoice::count());
        $new = Invoice::where('id', '!=', $paid->id)->sole();
        $response->assertRedirect(route('dashboard.invoices.show', $new));
        $this->assertSame(Invoice::STATUS_UNPAID, $new->status);
    }

    public function test_void_cancelled_same_service_invoice_does_not_block(): void
    {
        $cancelled = $this->invoice();
        $cancelled->forceFill(['status' => Invoice::STATUS_CANCELLED])->save();

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
    }

    public function test_open_invoice_for_a_different_service_does_not_block(): void
    {
        $this->invoice(); // open invoice for $this->fee (tuition "Обучение")

        // A second, distinct tuition service with its own tariff for this grade.
        $otherFee = Fee::create(['name_ru' => 'Продлёнка', 'category' => 'tuition', 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $otherFee->id,
            'academic_year_id' => $this->year->id,
            'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'yearly',
            'amount' => '800.00',
            'currency' => 'EGP',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload([
                'fee_id' => $otherFee->id,
            ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $new = Invoice::whereHas('items', fn ($query) => $query->where('fee_id', $otherFee->id))->sole();
        $this->assertSame('800.00', $new->total_amount);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
