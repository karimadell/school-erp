<?php

namespace Tests\Feature\Finance;

class QuickStudentRegistrationLiveSummaryTest extends QuickRegistrationUxTestCase
{
    public function test_page_exposes_russian_live_summary_contract_without_foreign_name_fields(): void
    {
        $this->structure();
        $this->fee();

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->assertSee('data-service-row', false)->assertSee('id="live-summary"', false)
            ->assertSee('Услуга')->assertSee('Стоимость')->assertSee('Оплачено')->assertSee('Остаток')
            ->assertSee('Общая стоимость')->assertSee('Всего оплачено')->assertSee('Общий остаток')
            ->assertSee('Оплаченная сумма не может превышать стоимость услуги.')
            ->assertSee('Выберите хотя бы одну финансовую услугу.')
            ->assertSee('Подтвердить оплату и завершить регистрацию')->assertSee('EGP')
            ->assertDontSee('name_en')->assertDontSee('name_ar')->assertDontSee('student_name_en');
    }
}
