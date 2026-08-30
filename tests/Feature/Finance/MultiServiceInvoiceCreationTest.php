<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MultiServiceInvoiceCreationTest extends FinanceOperationsTestCase
{
    private function service(string $name, string $category, string $amount, array $dimensions = []): array
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => '1.00', 'is_active' => true]);
        $price = FeePrice::create(array_merge([
            'fee_id' => $fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ], $dimensions));

        return [$fee, $price];
    }

    private function payload(array $fees, array $prices = [], array $extra = []): array
    {
        return array_replace_recursive([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01',
            'due_date' => '2027-01-01',
            'payment_type' => 'one_time',
            'fees' => collect($fees)->pluck('id')->all(),
            'fee_price_id' => collect($prices)->mapWithKeys(fn ($price) => [$price->fee_id => $price->id])->all(),
        ], $extra);
    }

    public function test_standalone_single_service_invoice_remains_compatible(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$this->fee]))
            ->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $item = $invoice->items->sole();

        $this->assertSame($this->fee->id, $item->fee_id);
        $this->assertSame('Обучение', $item->description);
        $this->assertSame(1, $item->quantity);
        $this->assertSame('1200.00', $item->unit_price);
        $this->assertSame('1200.00', $item->amount);
        $this->assertSame('1200.00', $invoice->total_amount);
    }

    public function test_multi_service_invoice_creates_one_canonical_snapshot_per_selected_service(): void
    {
        [$ordinary, $ordinaryPrice] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');
        [$food, $foodPrice] = $this->service('Питание', Fee::CATEGORY_FOOD, '450.00', ['option_type' => 'meal_plan', 'option_value' => 'monthly']);

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload([$this->fee, $ordinary, $food], [$ordinaryPrice, $foodPrice], [
                'option_type' => [$food->id => 'meal_plan'],
                'option_value' => [$food->id => 'monthly'],
            ]))->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('1950.00', $invoice->total_amount);
        $this->assertSame('1950.00', $invoice->items->reduce(fn ($sum, $item) => bcadd($sum, $item->amount, 2), '0.00'));
        $this->assertCount(3, $invoice->items);
        $this->assertEqualsCanonicalizing(['Обучение', 'Кружок', 'Питание'], $invoice->items->pluck('description')->all());
        $foodItem = $invoice->items->firstWhere('fee_id', $food->id);
        $this->assertSame('450.00', $foodItem->unit_price);
        $this->assertSame(1, $foodItem->quantity);
        $this->assertSame('monthly', $foodItem->metadata['option_value']);
        $this->assertSame($foodPrice->id, $foodItem->metadata['fee_price_id']);
        $this->assertSame('2026-09-01', $foodItem->metadata['pricing_date']);
    }

    public function test_tuition_and_transport_keep_independent_context_and_deselected_service_is_ignored(): void
    {
        [$transport, $transportPrice] = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT, '200.00', ['option_type' => 'zone', 'option_value' => 'A', 'payment_period' => 'monthly']);
        [$ignored, $ignoredPrice] = $this->service('Не выбран', Fee::CATEGORY_OTHER, '999.00');

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload([$this->fee, $transport], [$transportPrice], [
                'option_type' => [$transport->id => 'zone', $ignored->id => 'ignored'],
                'option_value' => [$transport->id => 'A', $ignored->id => 'ignored'],
                'payment_period' => [$transport->id => 'monthly'],
                'fee_price_id' => [$transport->id => $transportPrice->id, $ignored->id => $ignoredPrice->id],
            ]))->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('1400.00', $invoice->total_amount);
        $this->assertCount(2, $invoice->items);
        $this->assertFalse($invoice->items->contains('fee_id', $ignored->id));
        $this->assertArrayNotHasKey('option_value', $invoice->items->firstWhere('fee_id', $this->fee->id)->metadata);
    }

    public function test_duplicate_service_ids_are_rejected(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$this->fee], [], [
                'fees' => [$this->fee->id, $this->fee->id],
            ]))
            ->assertSessionHasErrors('fees.1');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_inactive_service_is_rejected(): void
    {
        [$fee, $price] = $this->service('Отключена', Fee::CATEGORY_OTHER, '100.00');
        $fee->update(['is_active' => false]);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$fee], [$price]))
            ->assertSessionHasErrors('fees.0');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_cross_category_dimensions_are_rejected(): void
    {
        [$ordinary, $ordinaryPrice] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload([$ordinary], [$ordinaryPrice], ['uniform_size' => [$ordinary->id => 'M']]))
            ->assertSessionHasErrors('items.0.size');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_selected_tariff_belonging_to_another_service_is_rejected(): void
    {
        [$ordinary] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');
        [, $otherPrice] = $this->service('Другой кружок', Fee::CATEGORY_OTHER, '400.00');

        $this->assertSelectedTariffIsRejected($ordinary, $otherPrice);
    }

    public function test_inactive_selected_tariff_is_rejected(): void
    {
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');
        $price->update(['is_active' => false]);

        $this->assertSelectedTariffIsRejected($fee, $price);
    }

    public function test_selected_tariff_with_wrong_currency_is_rejected(): void
    {
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');
        DB::table('fee_prices')->where('id', $price->id)->update(['currency' => 'USD']);

        $this->assertSelectedTariffIsRejected($fee, $price);
    }

    public function test_selected_tariff_from_wrong_academic_year_is_rejected(): void
    {
        $otherYear = AcademicYear::create([
            'name' => 'Другая версия 2026/2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'is_active' => false,
        ]);
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00', ['academic_year_id' => $otherYear->id]);

        $this->assertSelectedTariffIsRejected($fee, $price);
    }

    public function test_expired_selected_tariff_is_rejected(): void
    {
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00', ['end_date' => '2026-08-31']);

        $this->assertSelectedTariffIsRejected($fee, $price);
    }

    public function test_a_sole_not_yet_started_selected_tariff_is_usable_via_prepayment(): void
    {
        // Phase 4A.2 canonical rule: a sole same-fee/same-year/same-dimension
        // candidate is usable even before its own start_date — including
        // when explicitly selected by fee_price_id, not just via implicit
        // dimension search.
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00', ['start_date' => '2026-10-01']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$fee], [$price]))
            ->assertRedirect();

        $this->assertSame('300.00', Invoice::with('items')->sole()->total_amount);
    }

    public function test_an_explicitly_selected_tariff_is_rejected_when_a_same_year_sibling_makes_it_ambiguous(): void
    {
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00', ['start_date' => '2026-10-01', 'end_date' => '2026-12-31']);
        // A second same-fee/same-dimension tariff for a later period makes
        // the pre-window choice ambiguous — must fail rather than guess.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '350.00', 'currency' => 'EGP',
            'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->assertSelectedTariffIsRejected($fee, $price);
    }

    public function test_validated_pricing_date_is_used_for_authoritative_tariff_resolution(): void
    {
        [$fee, $price] = $this->service('Осенний кружок', Fee::CATEGORY_OTHER, '325.00', ['start_date' => '2026-10-01']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$fee], [$price], [
                'pricing_date' => '2026-10-15',
            ]))
            ->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('325.00', $invoice->total_amount);
        $this->assertSame('2026-10-15', $invoice->items->sole()->metadata['pricing_date']);
        $this->assertSame('2026-10-01', $invoice->items->sole()->metadata['tariff_valid_from']);
    }

    public function test_client_amount_is_rejected_and_empty_selection_is_rejected(): void
    {
        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload([$this->fee], [], ['total_amount' => '0.01']))->assertSessionHasErrors('total_amount');
        $this->post(route('dashboard.students.invoices.store', $this->student), $this->payload([], []))->assertSessionHasErrors('fees');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_browser_price_fields_are_ignored_and_server_tariff_controls_persisted_values(): void
    {
        [$fee, $price] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload([$fee], [$price], ['price' => '0.01', 'amount' => '0.01']))->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('300.00', $invoice->total_amount);
        $this->assertSame('300.00', $invoice->items->sole()->amount);
    }

    public function test_tuition_transport_food_and_uniform_context_is_persisted_from_tariffs(): void
    {
        [$tuition, $tuitionPrice] = $this->service('Обучение 1–4', Fee::CATEGORY_TUITION, '1000.00', ['grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        [$transport, $transportPrice] = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT, '200.00', ['option_type' => 'zone', 'option_value' => 'A', 'payment_period' => 'monthly']);
        [$food, $foodPrice] = $this->service('Питание', Fee::CATEGORY_FOOD, '150.00', ['option_type' => 'meal_plan', 'option_value' => 'monthly']);
        [$uniform, $uniformPrice] = $this->service('Форма', Fee::CATEGORY_UNIFORM, '250.00', ['item' => 'polo', 'size' => 'M']);
        $fees = [$tuition, $transport, $food, $uniform];
        $prices = [$tuitionPrice, $transportPrice, $foodPrice, $uniformPrice];

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student),
            $this->payload($fees, $prices, [
                'grade_group' => [$tuition->id => '1–4 классы'],
                'payment_period' => [$tuition->id => 'yearly', $transport->id => 'monthly'],
                'option_type' => [$transport->id => 'zone', $food->id => 'meal_plan'],
                'option_value' => [$transport->id => 'A', $food->id => 'monthly'],
                'uniform_item' => [$uniform->id => 'polo'],
                'uniform_size' => [$uniform->id => 'M'],
            ]))->assertRedirect();

        $invoice = Invoice::with('items')->sole();
        $items = $invoice->items->keyBy('fee_id');
        $this->assertSame(['1–4 классы', 'yearly'], [$items[$tuition->id]->metadata['grade_group'], $items[$tuition->id]->metadata['payment_period']]);
        $this->assertSame(['zone', 'A', 'monthly'], [$items[$transport->id]->metadata['option_type'], $items[$transport->id]->metadata['option_value'], $items[$transport->id]->metadata['payment_period']]);
        $this->assertSame(['meal_plan', 'monthly'], [$items[$food->id]->metadata['option_type'], $items[$food->id]->metadata['option_value']]);
        $this->assertSame(['polo', 'M'], [$items[$uniform->id]->metadata['item'], $items[$uniform->id]->metadata['size']]);

        $this->get(route('dashboard.invoices.show', $invoice))->assertOk()
            ->assertSee('1–4 классы')->assertSee('yearly')
            ->assertSee('zone')->assertSee('A')
            ->assertSee('meal_plan')->assertSee('monthly')
            ->assertSee('polo')->assertSee('M')
            ->assertDontSee('fee_price_id');
    }

    public function test_partial_and_full_payments_apply_to_whole_multi_service_invoice(): void
    {
        [$ordinary, $ordinaryPrice] = $this->service('Кружок', Fee::CATEGORY_OTHER, '300.00');
        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$this->fee, $ordinary], [$ordinaryPrice]));
        $invoice = Invoice::sole();
        $payments = app(InvoicePaymentService::class);
        $itemTuition = $invoice->items()->where('fee_id', $this->fee->id)->sole();
        $itemOrdinary = $invoice->items()->where('fee_id', $ordinary->id)->sole();

        // Finance V2, Phase 1C — this invoice is multi-item and
        // allocation-clean (brand new, zero prior payments/refunds), so an
        // explicit split is required; the amounts here are otherwise
        // arbitrary and don't change what this test is proving (whole-invoice
        // paid/remaining/status tracking across two payments).
        $payments->record($invoice->id, $this->cash->id, '500.00', 'cash', (string) Str::uuid(), $this->accountant, allocations: [
            ['invoice_item_id' => $itemTuition->id, 'amount' => '200.00'],
            ['invoice_item_id' => $itemOrdinary->id, 'amount' => '300.00'],
        ]);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
        $this->assertSame('1000.00', $invoice->fresh()->remaining_amount);

        $payments->record($invoice->id, $this->cash->id, '1000.00', 'cash', (string) Str::uuid(), $this->accountant, allocations: [
            ['invoice_item_id' => $itemTuition->id, 'amount' => '1000.00'],
        ]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_invoice_page_exposes_structured_transport_controls_and_stale_preview_protection(): void
    {
        [$transport] = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT, '13500.00', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'yearly',
        ]);
        foreach ([
            ['1500.00', 'Зона 1', 'monthly'], ['16200.00', 'Зона 2', 'yearly'], ['1800.00', 'Зона 2', 'monthly'],
        ] as [$amount, $zone, $period]) {
            FeePrice::create([
                'fee_id' => $transport->id, 'academic_year_id' => $this->year->id,
                'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30',
                'option_type' => 'zone', 'option_value' => $zone, 'payment_period' => $period, 'is_active' => true,
            ]);
        }

        $this->actingAs($this->accountant)->get(route('dashboard.students.invoices.create', $this->student))
            ->assertOk()
            ->assertSee('Зона тарифа')->assertSee('Период оплаты')->assertSee('Ежемесячно')->assertSee('За год')
            ->assertSee('transport-zone-select')->assertSee('transport-period-select')
            ->assertSee('previewGeneration')->assertSee('new AbortController()')->assertSee('generation !== previewGeneration')
            ->assertSee('Для выбранных параметров тариф не найден.')->assertSee('price-error');
    }

    public function test_detail_print_pdf_and_student_finance_render_each_payment_purpose(): void
    {
        [$transport, $transportPrice] = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT, '300.00', ['option_type' => 'zone', 'option_value' => 'A']);
        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$this->fee, $transport], [$transportPrice], [
            'option_type' => [$transport->id => 'zone'],
            'option_value' => [$transport->id => 'A'],
        ]));
        $invoice = Invoice::with(['items.fee', 'fees', 'student.grade', 'academicYear', 'payments'])->sole();

        $this->get(route('dashboard.invoices.show', $invoice))->assertOk()
            ->assertSee('Обучение')->assertSee('Трансфер')->assertSee('1200.00')->assertSee('300.00')
            ->assertSee('zone')->assertSee('A')->assertSee('1 500.00')->assertDontSee('fee_price_id');
        $this->get(route('dashboard.invoices.print', $invoice))->assertOk()
            ->assertSee('Назначение платежа')->assertSee('Обучение')->assertSee('Трансфер')
            ->assertSee('1,200.00')->assertSee('300.00')->assertSee('zone')->assertSee('A')
            ->assertSee('1,500.00')->assertDontSee('fee_price_id');
        $this->get(route('dashboard.students.finance', $this->student))->assertOk()->assertSee('Обучение, Трансфер');
        $pdf = view('dashboard.invoices.pdf', compact('invoice'))->render();
        foreach (['Обучение', 'Трансфер', '1,200.00', '300.00', 'Зона трансфера', 'A', '1,500.00'] as $expected) {
            $this->assertStringContainsString($expected, $pdf);
        }
        $this->assertStringNotContainsString('fee_price_id', $pdf);
    }

    private function assertSelectedTariffIsRejected(Fee $fee, FeePrice $price): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload([$fee], [], [
                'fee_price_id' => [$fee->id => $price->id],
            ]))
            ->assertSessionHasErrors('fees');

        $this->assertDatabaseCount('invoices', 0);
    }
}
