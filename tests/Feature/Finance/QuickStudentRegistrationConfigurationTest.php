<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;

class QuickStudentRegistrationConfigurationTest extends QuickRegistrationUxTestCase
{
    public function test_active_configuration_is_loaded_preselected_and_inactive_cash_is_hidden(): void
    {
        [$year, , , , $mode] = $this->structure();
        $this->fee();
        $active = CashAccount::create(['name' => 'Основная касса', 'type' => 'cash', 'is_active' => true]);
        CashAccount::create(['name' => 'Закрытая касса', 'type' => 'cash', 'is_active' => false]);

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->assertSee($year->name)->assertSee($mode->name_ru)->assertSee($active->name)
            ->assertDontSee('Закрытая касса')
            ->assertSee('value="'.$year->id.'" selected', false)
            ->assertSee('value="'.$mode->id.'" selected', false);
    }

    public function test_missing_year_and_mode_show_russian_warnings_and_block_post(): void
    {
        $this->fee();
        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->assertSee('Нет активного учебного года.')
            ->assertSee('Формы обучения не настроены.')->assertSee('disabled', false);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), [])
            ->assertSessionHasErrors(['academic_year_id', 'enrollment_mode_id']);
        $this->assertContains('Нет активного учебного года.', session('errors')->get('academic_year_id'));
        $this->assertContains('Формы обучения не настроены.', session('errors')->get('enrollment_mode_id'));
    }
}
