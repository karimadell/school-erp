<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\SchoolPriceListImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolPriceListEditabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preserves_snapshots_and_catalog_remains_versionable(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');
        $year = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $student = Student::create(['name' => 'Исторический ученик']);
        $invoice = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'customer_name' => 'Исторический ученик', 'subtotal_amount' => '999.00', 'total_amount' => '999.00', 'paid_amount' => '0.00', 'remaining_amount' => '999.00', 'status' => Invoice::STATUS_UNPAID, 'currency' => 'EGP']);
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => 'Старая цена', 'amount' => '999.00', 'unit_price' => '999.00', 'quantity' => 1, 'paid_amount' => '0.00', 'remaining_amount' => '999.00']);

        app(SchoolPriceListImportService::class)->import();

        $this->assertSame('999.00', $item->fresh()->amount);
        $this->actingAs($user)->get(route('dashboard.finance.services.create'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.finance.services.edit', $fee))->assertOk();
        $this->actingAs($user)->get(route('dashboard.finance.tariffs.create'))->assertOk();

        $next = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->actingAs($user)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $fee->id, 'academic_year_id' => $next->id, 'amount' => '47000.00',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'grade_group' => '1–4 классы',
            'payment_period' => 'yearly', 'is_active' => 1, 'change_reason' => 'Новая версия 2026/2027',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fee_prices', ['academic_year_id' => $next->id, 'amount' => '47000.00']);

        $this->actingAs($user)->post(route('dashboard.finance.services.store'), [
            'name_ru' => 'Новая услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fees', ['name_ru' => 'Новая услуга']);
    }
}
