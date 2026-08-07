<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Validation\ValidationException;

/**
 * Locks the canonical invoice-issuance seam that StudentInvoiceController::store()
 * now delegates to. The service must reproduce the previous inline behaviour
 * exactly: server-side tariff resolution, invoice numbering, item/pivot
 * snapshots, single-installment creation, audit logging, EGP totals, and the
 * issue date carried by created_at (set to the selected pricing date).
 */
class InvoiceIssuanceServiceTest extends FinanceOperationsTestCase
{
    private function data(array $overrides = []): array
    {
        return array_replace([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01',
            'pricing_date' => '2026-09-01',
            'items' => [[
                'fee_id' => $this->fee->id,
                'grade_group' => null,
                'payment_period' => 'yearly',
                'first_last_month' => false,
                'size' => null,
                'item' => null,
                'option_type' => null,
                'option_value' => null,
            ]],
            'payment_type' => 'one_time',
            'notes' => 'Комментарий сотрудника',
        ], $overrides);
    }

    public function test_service_issues_invoice_with_snapshots_numbering_installment_and_audit(): void
    {
        $invoice = app(InvoiceIssuanceService::class)
            ->issue($this->student, $this->data(), $this->accountant, '127.0.0.1', 'PHPUnit');

        // Server-resolved totals in EGP, no browser-supplied amounts.
        $this->assertSame('1200.00', $invoice->total_amount);
        $this->assertSame('1200.00', $invoice->subtotal_amount);
        $this->assertSame('1200.00', $invoice->remaining_amount);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('EGP', $invoice->currency);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame($this->accountant->id, $invoice->created_by);
        $this->assertSame('Комментарий сотрудника', $invoice->note);

        // Issue date is preserved via created_at, numbering derives its year from it.
        $this->assertSame('2026-09-01', $invoice->created_at->toDateString());
        $this->assertSame(Invoice::numberFor($invoice->id, '2026'), $invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->invoice_number);

        // Line-item snapshot and compatibility invoice_fee pivot row.
        $item = InvoiceItem::sole();
        $this->assertSame($this->fee->id, $item->fee_id);
        $this->assertSame('1200.00', $item->amount);
        $this->assertSame('1200.00', $item->unit_price);
        $this->assertSame('2026-09-01', $item->metadata['pricing_date']);
        $this->assertSame('1200.00', number_format((float) $invoice->fees()->first()->pivot->amount, 2, '.', ''));

        // A single installment is generated for the one-time path.
        $this->assertSame(1, $invoice->installments()->count());
        $this->assertSame('2027-01-01', $invoice->installments()->sole()->due_date->toDateString());

        // Audit trail is written for the creation.
        $this->assertDatabaseHas('audit_logs', [
            'model' => 'Invoice', 'model_id' => $invoice->id,
            'action' => 'created', 'user_id' => $this->accountant->id,
        ]);
        $this->assertSame(1, Invoice::count());
    }

    public function test_service_rejects_student_id_mismatch_and_persists_nothing(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(InvoiceIssuanceService::class)
                ->issue($this->student, $this->data(['student_id' => $this->student->id + 999]), $this->accountant);
        } finally {
            $this->assertDatabaseCount('invoices', 0);
        }
    }
}
