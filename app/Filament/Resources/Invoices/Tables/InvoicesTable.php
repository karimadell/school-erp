<?php

namespace App\Filament\Resources\Invoices\Tables;

use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('invoice_number')
                    ->label('Номер счёта')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('student.full_name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Плательщик')
                    ->searchable(),

                TextColumn::make('subtotal_amount')
                    ->label('Сумма услуг')
                    ->money('EGP'),

                TextColumn::make('total_amount')
                    ->label('Итого')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('currency')
                    ->label('Валюта'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),

                TextColumn::make('cashAccount.name')
                    ->label('Касса'),

                TextColumn::make('paid_at')
                    ->label('Дата оплаты')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable(),

            ])

            ->filters([
                //
            ])

            ->recordActions([

                ViewAction::make(),

                Action::make('print')
                    ->label('Печать')
                    ->icon('heroicon-o-printer')
                    ->url(fn ($record) => route('dashboard.invoices.print', $record))
                    ->openUrlInNewTab(),

            ])

            ->toolbarActions([]);
    }
}
