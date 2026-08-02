<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;

class QuickStudentRegistrationServicesTest extends QuickRegistrationUxTestCase
{
    public function test_only_active_services_appear_in_russian_groups_with_egp_prices(): void
    {
        $this->structure();
        foreach ([
            ['Вступительный взнос', Fee::CATEGORY_REGISTRATION], ['Годовое обучение', Fee::CATEGORY_TUITION],
            ['Школьный автобус', Fee::CATEGORY_TRANSPORT], ['Обед', Fee::CATEGORY_FOOD],
            ['Комплект формы', Fee::CATEGORY_UNIFORM], ['Учебники', Fee::CATEGORY_BOOKS],
        ] as [$name, $category]) {
            $this->fee($name, $category);
        }
        $this->fee('Отключённая услуга', Fee::CATEGORY_OTHER, false);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();
        foreach (['Регистрационный взнос', 'Обучение', 'Транспорт', 'Питание', 'Школьная форма', 'Дополнительные услуги'] as $group) {
            $response->assertSee($group);
        }
        $response->assertSee('Вступительный взнос')->assertSee('Учебники')->assertSee('1000.00 EGP')
            ->assertDontSee('Отключённая услуга')->assertDontSee('Активные услуги в этой категории не настроены.');
    }

    public function test_browser_price_cannot_control_the_invoice(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $payload = $this->payload($structure, $fee, ['services' => [[
            'fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'unit_price' => '1.00',
        ]]]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasErrors('services.0.unit_price');
        $this->assertDatabaseCount('invoices', 0);
    }
}
