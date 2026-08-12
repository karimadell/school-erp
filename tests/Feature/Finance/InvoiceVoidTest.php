<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\InvoiceCancellationService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\StudentBalanceService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceVoidTest extends FinanceOperationsTestCase
{
    private function service(): InvoiceCancellationService
    {
        return app(InvoiceCancellationService::class);
    }

    public function test_unpaid_invoice_can_be_voided(): void
    {
        $invoice = $this->invoice('1200.00');

        $result = $this->service()->void($invoice, 'Ошибочно выставлен', $this->accountant);

        $this->assertSame(Invoice::STATUS_CANCELLED, $result->status);
        $this->assertSame('0.00', (string) $result->remaining_amount);
    }

    public function test_void_retains_original_financial_data(): void
    {
        $invoice = $this->invoice('1200.00');
        $number = $invoice->invoice_number;
        $total = (string) $invoice->total_amount;
        $itemCount = $invoice->items()->count();
        $creator = $invoice->created_by;
        $issuedAt = $invoice->created_at->toDateTimeString();

        $this->service()->void($invoice, 'Дубликат', $this->accountant);
        $invoice->refresh();

        $this->assertSame($number, $invoice->invoice_number);
        $this->assertSame($total, (string) $invoice->total_amount);
        $this->assertSame($itemCount, $invoice->items()->count());
        $this->assertSame($creator, $invoice->created_by);
        $this->assertSame($issuedAt, $invoice->created_at->toDateTimeString());
    }

    public function test_void_requires_reason(): void
    {
        $invoice = $this->invoice();

        $this->expectException(ValidationException::class);
        $this->service()->void($invoice, '   ', $this->accountant);
    }

    public function test_void_records_user_and_time(): void
    {
        $invoice = $this->invoice();

        $this->service()->void($invoice, 'Причина', $this->accountant);
        $invoice->refresh();

        $this->assertSame($this->accountant->id, $invoice->cancelled_by);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertSame('Причина', $invoice->cancellation_reason);
    }

    public function test_voided_invoice_excluded_from_outstanding_balance(): void
    {
        $invoice = $this->invoice('1200.00');
        $balance = app(StudentBalanceService::class);

        $this->assertSame(1200.0, $balance->outstandingBalance($this->student));

        $this->service()->void($invoice, 'Отмена', $this->accountant);

        $this->assertSame(0.0, $balance->outstandingBalance($this->student->fresh()));
    }

    public function test_voided_invoice_cannot_accept_payment(): void
    {
        $invoice = $this->invoice('1200.00');
        $this->service()->void($invoice, 'Отмена', $this->accountant);

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '100.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );
    }

    public function test_paid_invoice_cannot_be_silently_voided(): void
    {
        $invoice = $this->invoice('1200.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '1200.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->expectException(ValidationException::class);
        $this->service()->void($invoice->fresh(), 'Отмена', $this->accountant);
    }

    public function test_already_void_invoice_cannot_be_voided_twice(): void
    {
        $invoice = $this->invoice();
        $this->service()->void($invoice, 'Первый раз', $this->accountant);

        $this->expectException(ValidationException::class);
        $this->service()->void($invoice->fresh(), 'Второй раз', $this->accountant);
    }

    public function test_void_writes_audit_log(): void
    {
        $invoice = $this->invoice();
        $this->service()->void($invoice, 'Аудит', $this->accountant);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_voided',
            'model' => 'Invoice',
            'model_id' => $invoice->id,
        ]);
    }

    // ----- Authorization ---------------------------------------------------

    public function test_void_route_requires_void_permission(): void
    {
        $invoice = $this->invoice();
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception'); // view invoices only

        $this->actingAs($reception)
            ->post(route('dashboard.invoices.void', $invoice), ['reason' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id, 'status' => Invoice::STATUS_CANCELLED]);
    }

    public function test_accountant_can_void_via_route(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->accountant)
            ->post(route('dashboard.invoices.void', $invoice), ['reason' => 'Ошибка'])
            ->assertRedirect(route('dashboard.invoices.show', $invoice));

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }
}
