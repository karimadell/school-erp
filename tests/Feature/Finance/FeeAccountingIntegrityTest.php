<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InvoicePayment;
use App\Models\InvoiceItem;
use App\Models\PaymentRefund;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

/**
 * P1 accounting integrity corrective: a Fee that has ever participated in
 * financial history (an InvoiceItem line or a FeePrice version) must never
 * be physically deletable — neither through the legacy dashboard route, nor
 * through Filament's Fee/FeePrice policies, nor at the model level. All
 * historical rows (InvoiceItem, Invoice totals, InvoicePayment,
 * PaymentRefund, ServiceCoverage, FeePrice) must remain byte-for-byte
 * unchanged, and the modern service catalog / tariff workflow must keep
 * working exactly as before.
 */
class FeeAccountingIntegrityTest extends FinanceOperationsTestCase
{
    public function test_legacy_fee_delete_is_blocked_and_full_financial_history_is_preserved(): void
    {
        $invoice = $this->invoice('1200.00');
        $item = InvoiceItem::where('invoice_id', $invoice->id)->sole();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '500.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $refund = app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '100.00',
            reason: 'Частичный возврат',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $feePrice = FeePrice::where('fee_id', $this->fee->id)->sole();

        $coverage = ServiceCoverage::create([
            'student_id' => $this->student->id,
            'fee_id' => $this->fee->id,
            'invoice_item_id' => $item->id,
            'fee_price_id' => $feePrice->id,
            'coverage_start' => '2026-08-01',
            'coverage_end' => '2027-06-30',
            'billing_unit' => 'monthly',
            'original_unit_price' => '1200.00',
            'created_by' => $this->accountant->id,
        ]);

        $feeSnapshot = $this->fee->fresh()->toArray();
        $itemSnapshot = $item->fresh()->toArray();
        $invoiceSnapshot = $invoice->fresh()->toArray();
        $paymentSnapshot = $payment->fresh()->toArray();
        $refundSnapshot = $refund->fresh()->toArray();
        $coverageSnapshot = $coverage->fresh()->toArray();

        // Proof 1: accountant has 'manage fees' but the legacy destroy route
        // must still fail closed.
        $this->assertTrue($this->accountant->can('manage fees'));
        $this->actingAs($this->accountant)
            ->delete(route('dashboard.fees.destroy', $this->fee))
            ->assertForbidden();

        // Proofs 2-7 / 18: nothing was actually removed or mutated.
        $this->assertNotNull(Fee::find($this->fee->id));
        $this->assertSame($feeSnapshot, $this->fee->fresh()->toArray());
        $this->assertSame($itemSnapshot, $item->fresh()->toArray());
        $this->assertSame($invoiceSnapshot, $invoice->fresh()->toArray());
        $this->assertSame($paymentSnapshot, $payment->fresh()->toArray());
        $this->assertSame($refundSnapshot, $refund->fresh()->toArray());
        $this->assertSame($coverageSnapshot, $coverage->fresh()->toArray());
    }

    public function test_fee_policy_delete_blocks_every_privileged_finance_role(): void
    {
        // Proof 8: FeePolicy::delete() must deny physical deletion for
        // every role that otherwise holds 'manage fees'.
        foreach (['super-admin', 'admin', 'principal', 'school-admin', 'accountant'] as $role) {
            $user = $this->user($role);
            $this->assertTrue($user->can('manage fees'), "{$role} is expected to hold manage fees");
            $this->assertFalse(Gate::forUser($user)->allows('delete', $this->fee), "{$role} must not be authorized to delete a Fee");
        }
    }

    public function test_filament_fee_bulk_delete_is_no_longer_authorized(): void
    {
        // Proof 9: Filament's DeleteBulkAction on FeeResource resolves
        // authorization through FeePolicy::delete() — already proven false
        // above for every privileged role, including the resource's own
        // gate check.
        $admin = $this->user('admin');
        $this->assertFalse($admin->can('delete', $this->fee));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Fee::class) || $admin->can('delete', $this->fee));
    }

    public function test_fee_price_policy_update_blocks_in_place_edit_and_preserves_the_row(): void
    {
        $feePrice = FeePrice::where('fee_id', $this->fee->id)->sole();
        $snapshot = $feePrice->fresh()->toArray();

        // Proof 10: privileged Finance roles can no longer edit a historical
        // FeePrice in place.
        foreach (['super-admin', 'admin', 'principal', 'school-admin', 'accountant'] as $role) {
            $user = $this->user($role);
            $this->assertTrue($user->can('manage fee prices'), "{$role} is expected to hold manage fee prices");
            $this->assertFalse(Gate::forUser($user)->allows('update', $feePrice), "{$role} must not be authorized to edit a FeePrice");
        }

        // Proof 10 (delete stays blocked, unchanged from before this task).
        $this->assertFalse(Gate::forUser($this->accountant)->allows('delete', $feePrice));

        // Proof 11: the row itself is untouched.
        $this->assertSame($snapshot, $feePrice->fresh()->toArray());
    }

    public function test_modern_service_catalog_remains_accessible_and_crud_still_works(): void
    {
        // Proof 12.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.services.index'))
            ->assertOk();

        // Proof 13: create still works.
        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.services.store'), [
                'name_ru' => 'Новая услуга',
                'category' => Fee::CATEGORY_OTHER,
                'type' => 'service',
                'is_active' => true,
                'is_non_refundable' => false,
            ])
            ->assertSessionDoesntHaveErrors();

        $created = Fee::where('name_ru', 'Новая услуга')->sole();

        // Proof 13: edit still works.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.services.edit', $created))
            ->assertOk();

        $this->actingAs($this->accountant)
            ->put(route('dashboard.finance.services.update', $created), [
                'name_ru' => 'Новая услуга (изм.)',
                'category' => Fee::CATEGORY_OTHER,
                'type' => 'service',
                'is_active' => true,
                'is_non_refundable' => false,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('Новая услуга (изм.)', $created->fresh()->name_ru);
    }

    public function test_fee_can_still_be_retired_via_is_active_false(): void
    {
        // Proof 14: the supported retirement workflow is deactivation, not
        // deletion, and it must remain fully functional.
        $this->assertTrue($this->fee->is_active);

        $this->actingAs($this->accountant)
            ->patch(route('dashboard.fees.toggle', $this->fee))
            ->assertRedirect();

        $this->assertFalse($this->fee->fresh()->is_active);
    }

    public function test_new_fee_price_version_via_canonical_tariff_workflow_still_works(): void
    {
        // Proof 15: a brand-new Fee (no existing tariff) can still receive
        // its first FeePrice version through dashboard.finance.tariffs.store.
        $fee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.tariffs.store'), [
                'fee_id' => $fee->id,
                'academic_year_id' => $this->year->id,
                'amount' => '1000.00',
                'currency' => 'EGP',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_active' => 1,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, FeePrice::where('fee_id', $fee->id)->count());
    }

    public function test_model_level_guard_rejects_deletion_of_a_fee_with_invoice_item_history(): void
    {
        // Proof 16: a Fee with InvoiceItem history but no FeePrice at all
        // must still be rejected — proves the InvoiceItem branch fires on
        // its own, independent of the FeePrice branch.
        $fee = Fee::create(['name_ru' => 'С историей начислений', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => '100.00', 'is_active' => true]);
        $invoice = $this->invoice('100.00');
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $fee->id,
            'description' => 'С историей начислений',
            'unit_price' => '100.00',
            'quantity' => 1,
            'amount' => '100.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '100.00',
        ]);

        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->count());

        try {
            $fee->delete();
            $this->fail('Deleting a Fee with InvoiceItem history must throw.');
        } catch (LogicException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNotNull(Fee::find($fee->id));
    }

    public function test_model_level_guard_rejects_deletion_of_a_fee_with_fee_price_history(): void
    {
        // Proof 17: a Fee with FeePrice history but no InvoiceItem at all
        // must still be rejected — proves the FeePrice branch fires on its
        // own, independent of the InvoiceItem branch.
        $fee = Fee::create(['name_ru' => 'С историей цен', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => '100.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => '100.00',
            'currency' => 'EGP',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        try {
            $fee->delete();
            $this->fail('Deleting a Fee with FeePrice history must throw.');
        } catch (LogicException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNotNull(Fee::find($fee->id));
        $this->assertSame(1, FeePrice::where('fee_id', $fee->id)->count());
    }
}
