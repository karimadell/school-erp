<?php

namespace Tests\Feature\Finance;

use App\Models\BillingBatch;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\Finance\MassBillingPreviewService;

/**
 * Phase 1 — Quick Registration document semantics must never weaken Mass
 * Billing's registration-fee duplicate guard. MassBillingEligibilityService
 * queries Invoice directly (no list-visibility scope applied), so a
 * zero-payment, origin=quick_registration obligation — invisible on the
 * default "Счета" list — must still be detected as "already charged" here.
 */
class MassBillingQuickRegistrationOriginTest extends MassBillingTestCase
{
    private function preview(BillingBatch $batch): array
    {
        return app(MassBillingPreviewService::class)->preview($batch);
    }

    private function row(array $result, Student $student): array
    {
        return collect($result['rows'])->firstWhere('student_id', $student->id);
    }

    private function seedQuickRegistrationInvoice(Student $student, Fee $fee): void
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => '7000.00', 'total_amount' => '7000.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '7000.00', 'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
            'origin' => Invoice::ORIGIN_QUICK_REGISTRATION,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru,
            'unit_price' => '7000.00', 'quantity' => 1, 'amount' => '7000.00', 'paid_amount' => '0.00', 'remaining_amount' => '7000.00',
        ]);
    }

    public function test_registration_duplicate_guard_still_detects_a_zero_payment_quick_registration_obligation(): void
    {
        $regFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $regFee->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'start_date' => '2026-05-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $alreadyCharged = $this->enrolledStudent($this->classA, suffix: 'QRDup');
        $fresh = $this->enrolledStudent($this->classA, suffix: 'QRNew');
        // Same shape as the current UAT observation: origin=quick_registration,
        // paid_amount=0.00 — invisible on the default "Счета" list, but still
        // a real obligation Mass Billing must not double-charge.
        $this->seedQuickRegistrationInvoice($alreadyCharged, $regFee);

        $batch = $this->makeBatch(classIds: [$this->classA->id], fee: $regFee);
        $result = $this->preview($batch);

        $this->assertFalse($this->row($result, $alreadyCharged)['eligible']);
        $this->assertSame(MassBillingPreviewService::SKIP_REGISTRATION_DUPLICATE, $this->row($result, $alreadyCharged)['skip_reason']);
        $this->assertTrue($this->row($result, $fresh)['eligible']);
    }
}
