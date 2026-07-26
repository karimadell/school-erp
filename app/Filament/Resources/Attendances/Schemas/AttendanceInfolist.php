<?php

namespace App\Filament\Resources\Attendances\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AttendanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('enrollment_id')
                    ->label(__('attendance.enrollment'))
                    ->numeric(),
                TextEntry::make('date')
                    ->label(__('attendance.date'))
                    ->date(),
                TextEntry::make('type')
                    ->label(__('attendance.type'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.' . $state))
                    ->badge(),
                TextEntry::make('period_id')
                    ->label(__('attendance.period'))
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->label(__('attendance.status'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.' . $state))
                    ->badge(),
                TextEntry::make('note')
                    ->label(__('attendance.note'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
