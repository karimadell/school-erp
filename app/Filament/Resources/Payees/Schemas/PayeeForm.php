<?php

namespace App\Filament\Resources\Payees\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PayeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('expenses.payee_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('expenses.payee_phone'))
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label(__('expenses.notes'))
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('expenses.is_active'))
                    ->default(true),
            ]);
    }
}
