<?php

namespace App\Filament\Resources\Fees\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name_ru')->label('Услуга'),
                TextEntry::make('category')
                    ->label('Категория')
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->label('Активна')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Изменена')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
