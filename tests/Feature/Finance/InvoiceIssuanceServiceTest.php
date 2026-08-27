<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StudentServiceSubscription;
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

    /**
     * Transaction/atomicity rule (Phase 2): a failure in installment
     * generation must roll back the invoice and its items too — there must
     * be no state where the invoice persists but its schedule doesn't.
     */
    public function test_a_failure_generating_the_installment_plan_rolls_back_the_whole_invoice(): void
    {
        try {
            app(InvoiceIssuanceService::class)->issue(
                $this->student,
                $this->data(['payment_type' => 'plan', 'payment_plan_id' => 999999]),
                $this->accountant,
            );
            $this->fail('Expected a ModelNotFoundException for the missing payment plan.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_installments', 0);
    }

    /**
     * Phase 2 — the resolver hook. This service must stay ignorant of how a
     * subscription is created: it only asks the caller and attaches whatever
     * id comes back, never importing StudentServiceSubscription/
     * StudentServiceSubscriptionService/MealSubscription itself.
     */
    public function test_resolver_is_invoked_for_a_line_with_no_existing_subscription_and_its_id_is_attached(): void
    {
        $calls = [];
        $resolver = function ($fee, $selection, $enrollment) use (&$calls) {
            $calls[] = [$fee->id, $enrollment->id];

            return $this->enrollment->serviceSubscriptions()->create([
                'fee_id' => $fee->id, 'start_date' => '2026-09-01', 'status' => StudentServiceSubscription::STATUS_ACTIVE,
            ])->id;
        };

        $invoice = app(InvoiceIssuanceService::class)
            ->issue($this->student, $this->data(), $this->accountant, subscriptionResolver: $resolver);

        $this->assertCount(1, $calls);
        $this->assertSame($this->fee->id, $calls[0][0]);
        $this->assertSame($this->enrollment->id, $calls[0][1]);
        $item = InvoiceItem::sole();
        $this->assertNotNull($item->subscription_id);
        $this->assertSame(StudentServiceSubscription::STATUS_ACTIVE, StudentServiceSubscription::find($item->subscription_id)->status);
    }

    public function test_a_null_resolver_leaves_the_line_unsubscribed_exactly_like_before(): void
    {
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, $this->data(), $this->accountant);

        $this->assertNull(InvoiceItem::sole()->subscription_id);
        $this->assertSame(0, StudentServiceSubscription::count());
    }

    public function test_an_existing_active_subscription_is_reused_and_the_resolver_is_never_called(): void
    {
        $existing = StudentServiceSubscription::create([
            'enrollment_id' => $this->enrollment->id, 'fee_id' => $this->fee->id,
            'start_date' => '2026-09-01', 'status' => StudentServiceSubscription::STATUS_ACTIVE,
        ]);
        $resolver = function () {
            $this->fail('The resolver must not be called when an active subscription already exists.');
        };

        $invoice = app(InvoiceIssuanceService::class)
            ->issue($this->student, $this->data(), $this->accountant, subscriptionResolver: $resolver);

        $this->assertSame($existing->id, InvoiceItem::sole()->subscription_id);
        $this->assertSame(1, StudentServiceSubscription::count());
    }
}
