<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Invoice;
use App\Models\CashTransaction;
use Carbon\Carbon;
use UnitEnum;
use BackedEnum;

class FinanceMonthlyReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Отчет доходы / расходы';

    protected string $view = 'filament.pages.finance-monthly-report';

    public $income = 0;
    public $expenses = 0;
    public $month;

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');

        $this->loadReport();
    }

    public function loadReport()
    {
        $start = Carbon::parse($this->month)->startOfMonth();
        $end = Carbon::parse($this->month)->endOfMonth();

        $this->income = Invoice::where('status','paid')
            ->whereBetween('created_at',[$start,$end])
            ->sum('total_amount');

        $this->expenses = CashTransaction::where('type','expense')
            ->whereBetween('created_at',[$start,$end])
            ->sum('amount');
    }
}