<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\AcademicYearPriceListService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearPriceListPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->year = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
    }

    public function test_pdf_route_requires_valid_year_and_existing_permission(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.pdf'))
            ->assertSessionHasErrors('academic_year_id');
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.pdf', ['academic_year_id' => 999999]))
            ->assertSessionHasErrors('academic_year_id');

        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get(route('dashboard.finance.tariffs.price-list.create'))->assertRedirect('/login');
    }

    public function test_pdf_response_and_russian_database_content(): void
    {
        $this->catalog();
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.pdf', ['academic_year_id' => $this->year->id]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $data = app(AcademicYearPriceListService::class)->data($this->year, array_keys(AcademicYearPriceListService::categoryOptions()), false);
        $html = view('dashboard.finance.tariffs.price-list-pdf', $data + ['generatedAt' => now(), 'logoPath' => public_path('images/school-logo.png')])->render();
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
        $this->assertStringContainsString('ПРАЙС', $html);
        $this->assertStringContainsString('2025–2026 учебный год', $html);
        $this->assertStringContainsString('1–4 классы', $html);
        $this->assertStringContainsString('>Год<', $html);
        $this->assertStringContainsString('Часть / месяц', $html);
        $this->assertStringContainsString('Каусер', $html);
        $this->assertStringContainsString('Комплексное питание', $html);
        $this->assertStringContainsString('Толстовка', $html);
        $this->assertStringContainsString('Размер 12–16', $html);
        $this->assertStringContainsString('EGP', $html);
        $this->assertStringNotContainsString('RUB', $html);
    }

    public function test_historical_years_export_independently_and_nothing_financial_is_mutated(): void
    {
        [$tuition] = $this->catalog();
        $next = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $next->id, 'amount' => '47000.00', 'currency' => 'EGP', 'start_date' => $next->start_date, 'end_date' => $next->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true]);
        $student = Student::create(['name' => 'Ученик']);
        $invoice = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'customer_name' => 'Ученик', 'subtotal_amount' => '39999.00', 'total_amount' => '39999.00', 'paid_amount' => '0.00', 'remaining_amount' => '39999.00', 'status' => Invoice::STATUS_UNPAID, 'currency' => 'EGP']);
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $tuition->id, 'description' => 'Историческая цена', 'amount' => '39999.00', 'unit_price' => '39999.00', 'quantity' => 1, 'paid_amount' => '0.00', 'remaining_amount' => '39999.00']);
        $before = ['tariffs' => FeePrice::count(), 'invoices' => Invoice::count(), 'items' => InvoiceItem::count(), 'payments' => InvoicePayment::count()];

        $oldData = app(AcademicYearPriceListService::class)->data($this->year, [Fee::CATEGORY_TUITION], false);
        $newData = app(AcademicYearPriceListService::class)->data($next, [Fee::CATEGORY_TUITION], false);
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.pdf', ['academic_year_id' => $this->year->id]))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.pdf', ['academic_year_id' => $next->id]))->assertOk();

        $this->assertTrue($oldData['tariffs']->contains('amount', '40500.00'));
        $this->assertFalse($oldData['tariffs']->contains('amount', '47000.00'));
        $this->assertTrue($newData['tariffs']->contains('amount', '47000.00'));
        $this->assertSame($before, ['tariffs' => FeePrice::count(), 'invoices' => Invoice::count(), 'items' => InvoiceItem::count(), 'payments' => InvoicePayment::count()]);
        $this->assertSame('39999.00', $item->fresh()->amount);
    }

    public function test_tariff_list_uses_descriptive_variant_labels_and_catalog_remains_editable(): void
    {
        [$tuition] = $this->catalog();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.index'))
            ->assertOk()->assertSee('Группа классов')->assertSee('Период оплаты')->assertSee('Позиция')
            ->assertSee('Размер')->assertSee('Вариант / транспортная зона')->assertSee('1–4 классы')->assertSee('За год')->assertSee('Скачать прайс-лист PDF');
        $this->actingAs($this->accountant)->get(route('dashboard.finance.services.create'))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create', ['fee_id' => $tuition->id]))->assertOk();
    }

    public function test_inactive_tariffs_are_excluded_by_default_and_can_be_included_explicitly(): void
    {
        [$tuition] = $this->catalog();
        $inactive = $this->price($tuition, '12345.00', ['grade_group' => 'Неактивная группа', 'payment_period' => 'yearly']);
        $inactive->update(['is_active' => false]);

        $default = app(AcademicYearPriceListService::class)->data($this->year, [Fee::CATEGORY_TUITION], false);
        $withInactive = app(AcademicYearPriceListService::class)->data($this->year, [Fee::CATEGORY_TUITION], true);

        $this->assertFalse($default['tariffs']->contains('id', $inactive->id));
        $this->assertTrue($withInactive['tariffs']->contains('id', $inactive->id));
        $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.price-list.create'))
            ->assertOk()->assertSee('Включить неактивные тарифы')
            ->assertDontSee('name="include_inactive" value="1" checked', false);
    }

    /** @return array<int,Fee> */
    private function catalog(): array
    {
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        $uniform = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        $this->price($tuition, '40500.00', ['grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        $this->price($tuition, '4500.00', ['grade_group' => '1–4 классы', 'payment_period' => 'monthly']);
        $this->price($transport, '1500.00', ['option_type' => 'Район', 'option_value' => 'Каусер', 'payment_period' => 'monthly']);
        $this->price($food, '170.00', ['item' => 'Комплексное питание', 'payment_period' => 'daily']);
        $this->price($uniform, '1200.00', ['item' => 'Толстовка', 'size' => '12–16', 'payment_period' => 'once']);

        return [$tuition, $transport, $food, $uniform];
    }

    private function fee(string $name, string $category): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
    }

    private function price(Fee $fee, string $amount, array $dimensions): FeePrice
    {
        return FeePrice::create(array_merge(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true], $dimensions));
    }
}
