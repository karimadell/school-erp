<?php

namespace App\Filament\Resources\Attendances\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('enrollment_id')
                    ->label(__('attendance.enrollment'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('date')
                    ->label(__('attendance.date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('attendance.type'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.' . $state))
                    ->badge(),
                TextColumn::make('period_id')
                    ->label(__('attendance.period'))
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('attendance.status'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.' . $state))
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
