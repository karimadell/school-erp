<?php
namespace Tests\Feature\Finance;
// Finance Workspace UX corrective: /dashboard/finance/services and
// /dashboard/finance/tariffs are no longer standalone sidebar entries (the
// Финансы group is now just Финансы/Приход/Расход/Касса/Отчёты) — both
// routes remain fully functional, as this test's own page-content
// assertions above still prove; only their sidebar links were removed.
class FinanceCatalogRussianUiTest extends ModernFinanceCatalogTestCase { public function test_dashboard_catalog_is_russian_egp_and_links_are_classic():void{$fee=$this->fee();$response=$this->actingAs($this->user)->get(route('dashboard.finance.tariffs.create',['fee_id'=>$fee->id]));$response->assertOk()->assertSee('Новый тариф')->assertSee('Цена, EGP')->assertSee('Класс и период оплаты')->assertSee('1–4 классы')->assertSee('Параметры специальной услуги')->assertDontSee('RUB');$sidebar=view('layouts.partials.shell-sidebar')->render();$this->assertStringNotContainsString('/dashboard/finance/services',$sidebar);$this->assertStringNotContainsString('/dashboard/finance/tariffs',$sidebar);$this->assertStringContainsString('/dashboard/finance/expenses',$sidebar);$this->assertStringNotContainsString('/admin/fee-prices',$sidebar);}}
