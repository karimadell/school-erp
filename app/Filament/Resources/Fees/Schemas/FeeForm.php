<?php

namespace App\Filament\Resources\Fees\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_ru')
                    ->label('Название услуги')
                    ->required(),
                Select::make('category')->label('Категория')->options([
                    'registration' => 'Регистрационный взнос', 'tuition' => 'Обучение',
                    'transport' => 'Транспорт', 'food' => 'Питание', 'uniform' => 'Школьная форма',
                    'books' => 'Книги', 'extra_classes' => 'Дополнительные занятия',
                    'activity' => 'Мероприятия', 'other' => 'Дополнительные услуги',
                ])->required(),
                Hidden::make('amount')->default('0.00'),
                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true)
                    ->required(),
            ]);
    }
}
