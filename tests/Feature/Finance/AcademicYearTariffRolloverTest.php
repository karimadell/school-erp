<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\AcademicYearTariffRolloverService;
use App\Services\Finance\InvoiceCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearTariffRolloverTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $sourceYear;
    private AcademicYear $targetYear;
    private Fee $fee;
    private FeePrice $sourceTariff;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->sourceYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $this->targetYear = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $this->sourceTariff = FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->sourceYear->id, 'amount' => '40500.00', 'currency' => 'EGP',
            'start_date' => $this->sourceYear->start_date, 'end_date' => $this->sourceYear->end_date,
            'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'option_type' => 'Форма', 'option_value' => 'Очная',
            'size' => '10', 'item' => 'Комплект', 'notes' => 'Исходная запись', 'change_reason' => 'Прайс 2025/2026', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_rolled_over_tariff_is_payable_before_the_academic_year_starts(): void
    {
        // A school rolls next year's price list over in June, then invoices a parent
        // in July — long before the 1 September start. The rolled tariff must resolve.
        CarbonImmutable::setTestNow('2026-06-15 09:00:00');
        app(AcademicYearTariffRolloverService::class)->copy($this->sourceYear->id, $this->targetYear->id);

        $copy = FeePrice::where('academic_year_id', $this->targetYear->id)->sole();
        $this->assertSame('2026-06-15', $copy->start_date->toDateString());

        // Prepayment date is before the school year start (2026-09-01) yet the tariff resolves.
        $result = app(InvoiceCalculationService::class)->calculate(
            [[
                'fee_id' => $this->fee->id, 'quantity' => 1, 'grade_group' => '1–4 классы',
                'payment_period' => 'yearly', 'option_type' => 'Форма', 'option_value' => 'Очная',
                'size' => '10', 'item' => 'Комплект',
            ]],
            pricingDate: '2026-07-20',
            academicYearId: $this->targetYear->id,
        );

        $this->assertSame('40500.00', $result['total_amount']);
        $this->assertSame('2026-06-15', $result['line_items'][0]['tariff_valid_from']);
        $this->assertSame('2027-06-30', $result['line_items'][0]['tariff_valid_to']);
    }

    public function test_dashboard_wizard_previews_and_copies_tariffs(): void
    {
        // Freeze "now" before the year starts so the copied tariff's window opens
        // at rollover time, making the new year's price payable months early.
        CarbonImmutable::setTestNow('2026-06-15 10:00:00');

        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.index'))
            ->assertOk()->assertSee('Скопировать тарифы');
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.rollover.create'))
            ->assertOk()->assertSee('Исходный учебный год')->assertSee('Целевой учебный год');
        $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.rollover.preview'), [
            'source_academic_year_id' => $this->sourceYear->id, 'target_academic_year_id' => $this->targetYear->id,
        ])->assertOk()->assertSee('Найдено тарифов')->assertSee('Старые тарифы не изменяются.');
        $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.rollover.store'), [
            'source_academic_year_id' => $this->sourceYear->id, 'target_academic_year_id' => $this->targetYear->id, 'confirmed' => 1,
        ])->assertRedirect(route('dashboard.finance.tariffs.index', ['academic_year_id' => $this->targetYear->id]));

        $copy = FeePrice::where('academic_year_id', $this->targetYear->id)->sole();
        $this->assertNotSame($this->sourceTariff->id, $copy->id);
        $this->assertSame('40500.00', $copy->amount);
        $this->assertSame('EGP', $copy->currency);
        $this->assertSame('1–4 классы', $copy->grade_group);
        $this->assertSame('yearly', $copy->payment_period);
        $this->assertSame('Форма', $copy->option_type);
        $this->assertSame('Очная', $copy->option_value);
        $this->assertSame('10', $copy->size);
        $this->assertSame('Комплект', $copy->item);
        // Window opens at rollover time (before the school year), not on the year start,
        // so tuition can be invoiced and prepaid before classes begin. It still runs
        // through the whole year via end_date.
        $this->assertSame('2026-06-15', $copy->start_date->toDateString());
        $this->assertSame('2027-06-30', $copy->end_date->toDateString());
    }

    public function test_source_history_is_unchanged_and_target_copy_is_independent(): void
    {
        app(AcademicYearTariffRolloverService::class)->copy($this->sourceYear->id, $this->targetYear->id);
        $copy = FeePrice::where('academic_year_id', $this->targetYear->id)->sole();

        $copy->update(['amount' => '42000.00', 'change_reason' => 'Ручная корректировка целевого года']);

        $this->assertSame('40500.00', $this->sourceTariff->fresh()->amount);
        $this->assertSame('Прайс 2025/2026', $this->sourceTariff->fresh()->change_reason);
        $this->assertSame('42000.00', $copy->fresh()->amount);
    }

    public function test_existing_target_variant_is_skipped_without_overwrite(): void
    {
        $manual = FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->targetYear->id, 'amount' => '43000.00', 'currency' => 'EGP',
            'start_date' => $this->targetYear->start_date, 'end_date' => $this->targetYear->end_date,
            'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'option_type' => 'Форма', 'option_value' => 'Очная',
            'size' => '10', 'item' => 'Комплект', 'is_active' => true,
        ]);

        $preview = app(AcademicYearTariffRolloverService::class)->preview($this->sourceYear->id, $this->targetYear->id);
        $result = app(AcademicYearTariffRolloverService::class)->copy($this->sourceYear->id, $this->targetYear->id);

        $this->assertSame(1, $preview['skipped']);
        $this->assertSame(0, $preview['new_tariffs']);
        $this->assertSame(['created' => 0, 'skipped' => 1], $result);
        $this->assertSame('43000.00', $manual->fresh()->amount);
        $this->assertSame(1, FeePrice::where('academic_year_id', $this->targetYear->id)->count());
    }

    public function test_invoice_snapshot_is_never_recalculated(): void
    {
        $student = Student::create(['name' => 'Исторический ученик']);
        $invoice = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $this->sourceYear->id, 'customer_name' => $student->name, 'subtotal_amount' => '39999.00', 'total_amount' => '39999.00', 'paid_amount' => '0.00', 'remaining_amount' => '39999.00', 'status' => Invoice::STATUS_UNPAID, 'currency' => 'EGP']);
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение', 'amount' => '39999.00', 'unit_price' => '39999.00', 'quantity' => 1, 'paid_amount' => '0.00', 'remaining_amount' => '39999.00']);

        app(AcademicYearTariffRolloverService::class)->copy($this->sourceYear->id, $this->targetYear->id);

        $this->assertSame('39999.00', $item->fresh()->amount);
        $this->assertSame('39999.00', $invoice->fresh()->total_amount);
    }

    public function test_permission_and_validation_are_reused(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get(route('dashboard.finance.tariffs.rollover.create'))->assertRedirect('/login');
        $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.rollover.preview'), [
            'source_academic_year_id' => $this->sourceYear->id, 'target_academic_year_id' => $this->sourceYear->id,
        ])->assertSessionHasErrors('target_academic_year_id');
    }
}
