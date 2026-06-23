<?php

namespace App\Filament\Resources\TeacherSalaries\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeacherSalaryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('teacher_id')
                    ->required()
                    ->numeric(),
                TextInput::make('base_salary')
                    ->required()
                    ->numeric(),
                TextInput::make('deductions')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('bonus')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('net_salary')
                    ->required()
                    ->numeric(),
                DatePicker::make('salary_month')
                    ->required(),
            ]);
    }
}
