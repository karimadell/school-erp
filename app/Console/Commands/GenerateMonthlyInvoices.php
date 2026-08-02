<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate-monthly';

    protected $description = 'Generate monthly invoices automatically';

    public function handle()
    {
        $this->error('Автоматическое создание ежемесячных счетов временно отключено до перевода на безопасный сервис расчёта F1B.');

        return self::FAILURE;
    }
}
