<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('category'),
                Textarea::make('description')
                    ->columnSpanFull(),
                DatePicker::make('expense_date')
                    ->required(),
                TextInput::make('cash_account_id')
                    ->numeric(),
            ]);
    }
    
}
