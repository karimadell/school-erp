<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard: InvoicesTable and TeacherSalaryResource used to import
 * their record action from the stale Filament\Tables\Actions namespace
 * (a v3-era path that doesn't exist in this installed Filament version —
 * the real class lives under Filament\Actions). Both pages fatally errored
 * with "Class ... not found" on every visit until the imports were
 * corrected to Filament\Actions\{Action,EditAction}.
 */
class FilamentActionsNamespaceRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_invoices_list_page_loads_without_error(): void
    {
        Livewire::actingAs($this->admin())->test(ListInvoices::class)->assertSuccessful();
    }

    public function test_the_teacher_salaries_list_page_loads_without_error(): void
    {
        Livewire::actingAs($this->admin())->test(ListTeacherSalaries::class)->assertSuccessful();
    }
}
