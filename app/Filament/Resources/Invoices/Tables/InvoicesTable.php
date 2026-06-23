<?php

namespace App\Filament\Resources\Invoices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('id')
                    ->label('№ Счета')
                    ->sortable(),

                TextColumn::make('student.full_name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Плательщик')
                    ->searchable(),

                TextColumn::make('total_amount')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable(),

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

                EditAction::make(),

                Action::make('print')
                    ->label('Печать')
                    ->icon('heroicon-o-printer')
                    ->url(fn ($record) => route('invoice.print', $record->id))
                    ->openUrlInNewTab(),

            ])

            ->toolbarActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),

            ]);
    }
}