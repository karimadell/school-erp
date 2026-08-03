<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Models\FeePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePriceManagementUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_resource_is_russian_and_classic_pages_redirect_to_canonical_module(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $this->assertSame('Цены на услуги', FeePriceResource::getNavigationLabel());
        $this->actingAs($user)->get(route('dashboard.fee-prices.index'))
            ->assertRedirect(FeePriceResource::getUrl('index'));
        $this->actingAs($user)->get(route('dashboard.fee-prices.create'))
            ->assertRedirect(FeePriceResource::getUrl('create'));
    }

    public function test_historical_price_deletion_is_forbidden(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');
        $price = new FeePrice();

        $this->assertFalse($user->can('delete', $price));
    }
}
