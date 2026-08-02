<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('invoice_number')
                    ->label('Номер счёта')
                    ->placeholder('—'),
                TextEntry::make('currency')
                    ->label('Валюта')
                    ->default('EGP'),
                TextEntry::make('student_id')
                    ->numeric(),
                TextEntry::make('customer_name')
                    ->placeholder('-'),
                TextEntry::make('subtotal_amount')
                    ->label('Сумма услуг')
                    ->money('EGP'),
                TextEntry::make('total_amount')
                    ->label('Итого')
                    ->money('EGP'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('cash_account_id')
                    ->numeric(),
                TextEntry::make('paid_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
