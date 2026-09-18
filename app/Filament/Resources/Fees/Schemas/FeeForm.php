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
                Select::make('category')->label(__('finance_uat.service_kind'))->options([
                    'registration' => 'Регистрационный взнос', 'tuition' => 'Обучение',
                    // Legacy Tuition-family categories — pre-date the
                    // unified, EnrollmentMode-scoped Tuition Fee (see
                    // FeePriceResource's own Study Mode selector) and are
                    // not offered for NEW Fees, but must remain
                    // recognized here so an existing Fee already carrying
                    // one of these categories still shows a labeled
                    // selection instead of a blank/invalid one.
                    'tuition_regular' => 'Обычное обучение', 'tuition_family' => 'Семейное обучение',
                    'tuition_external' => 'Экстернат',
                    'transport' => 'Транспорт', 'food' => 'Питание', 'uniform' => 'Школьная форма',
                    'books' => 'Книги', 'extra_classes' => 'Дополнительные занятия',
                    'activity' => 'Мероприятия', 'other' => 'Дополнительные услуги',
                ])->helperText(__('finance_uat.service_kind_help'))->required(),
                Hidden::make('amount')->default('0.00'),
                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true)
                    ->required(),
            ]);
    }
}
