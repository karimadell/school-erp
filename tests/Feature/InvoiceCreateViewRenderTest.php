<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for a stray "@@@foreach" typo in
 * resources/views/dashboard/invoices/create.blade.php (line 312) that left
 * its matching @endforeach orphaned and broke the Blade compiler for the
 * whole file. A uniform-category Fee is required so the view's
 * $uniformFee-gated block (containing the fixed loop) actually renders.
 */
class InvoiceCreateViewRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_create_page_renders_and_lists_uniform_sizes(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('accountant');

        Fee::create([
            'name_ru' => 'Школьная форма',
            'category' => Fee::CATEGORY_UNIFORM,
            'amount' => 500,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.invoices.create'));

        $response->assertOk();
        $response->assertSee('XXL');
    }
}
