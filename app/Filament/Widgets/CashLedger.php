<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\CashTransaction;

class CashLedger extends TableWidget
{
    protected static ?string $heading = 'Движение кассы';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CashTransaction::query()->latest()
            )
            ->columns([

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип операции'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('cashAccount.name')
                    ->label('Касса'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Комментарий'),

            ]);
    }
}