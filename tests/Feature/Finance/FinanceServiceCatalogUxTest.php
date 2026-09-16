<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InvoiceItem;

/**
 * Services catalog UX corrective: /dashboard/finance/services must exclude
 * is_test_data=true Fees (never by hardcoded name), keep every real Fee
 * visible regardless of is_active, support name/category/status GET
 * filters preserved through pagination, and keep the page's unchanged
 * contract (title, primary CTA, no destructive action, Открыть → the
 * existing show route) intact.
 */
class FinanceServiceCatalogUxTest extends FinanceOperationsTestCase
{
    public function test_test_data_fee_is_excluded_but_real_fee_is_visible(): void
    {
        $testFee = Fee::create(['name_ru' => 'UAT — Тестовая услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true, 'is_test_data' => true]);
        $realFee = Fee::create(['name_ru' => 'Реальная услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertFalse($ids->contains($testFee->id));
        $this->assertTrue($ids->contains($realFee->id));
    }

    public function test_real_inactive_fee_remains_visible(): void
    {
        $inactive = Fee::create(['name_ru' => 'Неактивная реальная услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));

        $response->assertOk();
        $this->assertTrue($response->viewData('services')->pluck('id')->contains($inactive->id));
    }

    public function test_search_finds_matching_service_and_excludes_non_matching(): void
    {
        $match = Fee::create(['name_ru' => 'Школьный автобус', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service', 'amount' => 0, 'is_active' => true]);
        $other = Fee::create(['name_ru' => 'Обед', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index', ['search' => 'автобус']));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_category_filter_works(): void
    {
        $transport = Fee::create(['name_ru' => 'Транспорт услуга', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service', 'amount' => 0, 'is_active' => true]);
        $food = Fee::create(['name_ru' => 'Питание услуга', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index', ['category' => Fee::CATEGORY_TRANSPORT]));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($transport->id));
        $this->assertFalse($ids->contains($food->id));
    }

    public function test_active_status_filter_shows_only_active(): void
    {
        $active = Fee::create(['name_ru' => 'Активная услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);
        $inactive = Fee::create(['name_ru' => 'Неактивная услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index', ['status' => 'active']));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_inactive_status_filter_shows_only_inactive(): void
    {
        $active = Fee::create(['name_ru' => 'Активная услуга 2', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);
        $inactive = Fee::create(['name_ru' => 'Неактивная услуга 2', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index', ['status' => 'inactive']));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($active->id));
    }

    public function test_filters_are_preserved_through_pagination(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Fee::create(['name_ru' => "Услуга пагинации {$i}", 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);
        }

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index', ['category' => Fee::CATEGORY_OTHER]));

        $response->assertOk();
        $services = $response->viewData('services');
        $this->assertSame(30, $services->total());
        $this->assertTrue($services->hasMorePages());
        $response->assertSee('category='.Fee::CATEGORY_OTHER, false);
    }

    public function test_open_action_links_to_existing_show_route(): void
    {
        $fee = Fee::create(['name_ru' => 'Услуга для открытия', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));

        $response->assertOk();
        $response->assertSee('Открыть');
        $response->assertSee(route('dashboard.finance.services.show', $fee), false);
    }

    public function test_page_title_and_primary_cta_unchanged(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));

        $response->assertOk();
        $response->assertSee('Услуги и сборы');
        $response->assertSee('Добавить услугу');
    }

    public function test_historical_test_flagged_fee_is_hidden_but_history_is_preserved_and_show_route_still_works(): void
    {
        $testFee = Fee::create(['name_ru' => 'UAT — Историческая услуга', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true, 'is_test_data' => true]);
        $feePrice = FeePrice::create(['fee_id' => $testFee->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $invoice = $this->invoice('500.00');
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $testFee->id, 'description' => 'UAT — Историческая услуга', 'unit_price' => '500.00', 'quantity' => 1, 'amount' => '500.00', 'paid_amount' => '0.00', 'remaining_amount' => '500.00']);

        $feePriceSnapshot = $feePrice->fresh()->toArray();
        $itemSnapshot = $item->fresh()->toArray();

        $indexResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));
        $indexResponse->assertOk();
        $this->assertFalse($indexResponse->viewData('services')->pluck('id')->contains($testFee->id));

        // Historical records are completely untouched by the index filter.
        $this->assertSame($feePriceSnapshot, $feePrice->fresh()->toArray());
        $this->assertSame($itemSnapshot, $item->fresh()->toArray());

        // Direct show route is unfiltered by is_test_data — unchanged existing behavior.
        $showResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.show', $testFee));
        $showResponse->assertOk();
    }

    public function test_no_destructive_action_is_introduced(): void
    {
        Fee::create(['name_ru' => 'Услуга без удаления', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.index'));

        $response->assertOk();
        $response->assertDontSee('Удалить');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('dashboard.finance.services.destroy'));
    }
}
