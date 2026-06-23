<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Invoice;
use App\Models\CashTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use UnitEnum;
use BackedEnum;

class FinancialReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Финансовый отчет';

    protected static ?string $title = 'Финансовый отчет';

    protected string $view = 'filament.pages.financial-report';

    public $totalIncome = 0;
    public $paidInvoices = 0;
    public $unpaidInvoices = 0;
    public $cashBalance = 0;

    public function mount(): void
    {
        $this->totalIncome = Invoice::where('status','paid')->sum('total_amount');

        $this->paidInvoices = Invoice::where('status','paid')->count();

        $this->unpaidInvoices = Invoice::where('status','unpaid')->count();

        $this->cashBalance = CashTransaction::sum('amount');
    }

    public function downloadPdf()
    {
        $invoices = Invoice::latest()->limit(50)->get();

        $pdf = Pdf::loadView('pdf.financial-report',[
            'totalIncome'=>$this->totalIncome,
            'paidInvoices'=>$this->paidInvoices,
            'unpaidInvoices'=>$this->unpaidInvoices,
            'cashBalance'=>$this->cashBalance,
            'invoices'=>$invoices
        ]);

        return response()->streamDownload(
            fn()=>print($pdf->output()),
            'financial_report.pdf'
        );
    }
}