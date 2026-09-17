<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;

class QuickStudentRegistrationPaymentUxTest extends QuickRegistrationUxTestCase
{
    public function test_zero_payment_needs_no_payment_details_but_positive_payment_requires_active_details(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1.00']],
        ]))->assertSessionHasErrors(['cash_account_id', 'payment_method']);

        // cash always resolves to the canonical operating account
        // (CashAccount::resolvePaymentAccountId) regardless of any
        // cash_account_id submitted, so the only way left to exercise an
        // inactive-account rejection is to deactivate that canonical
        // account itself.
        CashAccount::operating()->update(['is_active' => false]);
        // Finance UAT corrective (P0) — the first call above already
        // succeeded and created a real "Иванов Иван" Student, so resubmitting
        // that exact identity here would (correctly) trigger the new
        // duplicate-Student identity check before ever reaching the
        // cash-account validation this test actually targets. A distinct
        // name keeps this call testing what it always tested, unrelated to
        // identity resolution (covered separately by
        // QuickStudentRegistrationIdentityResolutionTest).
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'student_last_name_ru' => 'Сидоров',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1.00']],
            'payment_method' => 'cash',
        ]))->assertSessionHasErrors('cash_account_id');
        $this->assertSame('Выбранная касса неактивна.', session('errors')->first('cash_account_id'));
    }

    public function test_overpayment_is_rejected_in_russian(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $cash = CashAccount::create(['name' => 'Касса', 'type' => 'cash', 'is_active' => true]);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.01']],
            'cash_account_id' => $cash->id, 'payment_method' => 'cash',
        ]))->assertSessionHasErrors('services.0.paid_now');
        $this->assertSame('Оплата по услуге не может превышать её рассчитанную стоимость.', session('errors')->first('services.0.paid_now'));
    }
}
