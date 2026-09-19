<?php

namespace Tests\Feature\Finance;

// Finance Workspace UX corrective: /dashboard/finance/services remains a
// technical settings link (reached from Приход), not a standalone sidebar
// entry. /dashboard/finance/tariffs ("Цены на услуги") is now a direct,
// owner-approved operational Finance sidebar entry (Dashboard-native
// pricing workflow corrective) — Filament's separate /admin/fee-prices
// layout stays never linked from here.
class FinanceCatalogRussianUiTest extends ModernFinanceCatalogTestCase
{
    public function test_dashboard_catalog_is_russian_egp_and_links_are_classic(): void
    {
        $fee = $this->fee();
        $response = $this->actingAs($this->user)->get(route('dashboard.finance.tariffs.create', ['fee_id' => $fee->id]));
        $response->assertOk()->assertSee('Новый тариф')->assertSee('Цена, EGP')->assertSee('Класс и период оплаты')->assertSee('1–4 классы')->assertSee('Параметры специальной услуги')->assertDontSee('RUB');
        $sidebar = view('layouts.partials.shell-sidebar')->render();
        $this->assertStringNotContainsString('/dashboard/finance/services', $sidebar);
        $this->assertStringContainsString('/dashboard/finance/tariffs', $sidebar);
        $this->assertStringContainsString('/dashboard/finance/expenses', $sidebar);
        $this->assertStringNotContainsString('/admin/fee-prices', $sidebar);
    }
}
