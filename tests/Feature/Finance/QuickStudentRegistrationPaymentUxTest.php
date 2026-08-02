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

        $inactive = CashAccount::create(['name' => 'Закрытая касса', 'type' => 'cash', 'is_active' => false]);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1.00']],
            'cash_account_id' => $inactive->id, 'payment_method' => 'cash',
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
