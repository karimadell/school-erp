<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePriceManagementUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_resource_is_admin_only_and_dashboard_pages_redirect_to_canonical_module(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $this->assertSame('Цены на услуги', FeePriceResource::getNavigationLabel());
        $this->assertFalse(FeePriceResource::shouldRegisterNavigation());
        $this->actingAs($user)->get(route('dashboard.fee-prices.index'))
            ->assertRedirect(route('dashboard.finance.tariffs.index'));
        $this->actingAs($user)->get(route('dashboard.fee-prices.create'))
            ->assertRedirect(route('dashboard.finance.tariffs.create'));
    }

    public function test_historical_price_deletion_is_forbidden(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');
        $price = new FeePrice();

        $this->assertFalse($user->can('delete', $price));
    }

    public function test_grade_group_is_a_controlled_value_on_the_dashboard(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $fee = Fee::create([
            'name_ru' => 'Обучение',
            'category' => Fee::CATEGORY_TUITION,
            'amount' => '1.00',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('dashboard.finance.tariffs.create'))
            ->assertOk()
            ->assertSee('1–4 классы')
            ->assertSee('9–11 классы');

        $this->actingAs($user)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $fee->id,
            'academic_year_id' => $year->id,
            'amount' => '100.00',
            'start_date' => '2026-08-01',
            'is_active' => '1',
            'grade_group' => 'произвольная группа',
        ])->assertSessionHasErrors('grade_group');

        $this->assertDatabaseCount('fee_prices', 0);
    }
}
