<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceFilamentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('accountant');
    }

    private function invoice(): Invoice
    {
        $student = Student::create(['name' => 'Ученик']);
        $account = CashAccount::create(['name' => 'Касса', 'type' => 'cash']);

        $invoice = Invoice::create([
            'student_id' => $student->id, 'cash_account_id' => $account->id,
            'subtotal_amount' => 100, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, 2026);
        $invoice->save();

        return $invoice;
    }

    public function test_list_and_view_remain_available(): void
    {
        $invoice = $this->invoice();
        Livewire::actingAs($this->user)->test(ListInvoices::class)
            ->assertSuccessful()->assertSee($invoice->invoice_number)->assertSee('EGP');
        Livewire::actingAs($this->user)->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSuccessful()->assertSee($invoice->invoice_number)->assertSee('EGP');
    }

    public function test_create_and_edit_routes_are_not_registered(): void
    {
        $pages = InvoiceResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_delete_and_bulk_delete_actions_are_not_rendered(): void
    {
        $this->invoice();
        Livewire::actingAs($this->user)
            ->test(ListInvoices::class)
            ->assertDontSee('Удалить')
            ->assertDontSee('Delete');
    }
}
