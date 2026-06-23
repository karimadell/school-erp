<?php

namespace App\Exports;

use App\Models\CashTransaction;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class CashReportExport implements FromView
{
    protected string $type;
    protected string $value;

    /**
     * @param string $type  daily | monthly
     * @param string $value date (Y-m-d) OR month (Y-m)
     */
    public function __construct(string $type, string $value)
    {
        $this->type  = $type;
        $this->value = $value;
    }

    public function view(): View
    {
        if ($this->type === 'monthly') {

            $month = substr($this->value, 5, 2);
            $year  = substr($this->value, 0, 4);

            $transactions = CashTransaction::with('cashAccount')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at')
                ->get();

            return view('dashboard.cash.reports-excel', [
                'transactions' => $transactions,
                'title'        => "Monthly Cash Report - {$this->value}",
            ]);
        }

        // DAILY (default)
        $transactions = CashTransaction::with('cashAccount')
            ->whereDate('created_at', $this->value)
            ->orderBy('created_at')
            ->get();

        return view('dashboard.cash.reports-excel', [
            'transactions' => $transactions,
            'title'        => "Daily Cash Report - {$this->value}",
        ]);
    }
}