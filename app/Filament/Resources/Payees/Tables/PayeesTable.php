<?php

namespace App\Filament\Resources\Payees\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PayeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('expenses.payee_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('expenses.payee_phone'))
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label(__('expenses.is_active'))
                    ->boolean(),
                TextColumn::make('expenses_count')
                    ->label(__('expenses.expenses_count'))
                    ->counts('expenses'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
