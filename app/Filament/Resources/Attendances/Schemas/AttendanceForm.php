<?php

namespace App\Filament\Resources\Attendances\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('enrollment_id')
                    ->required()
                    ->numeric(),
                DatePicker::make('date')
                    ->required(),
                Select::make('type')
                    ->options(['daily' => 'Daily', 'period' => 'Period'])
                    ->default('daily')
                    ->required(),
                TextInput::make('period_id')
                    ->numeric()
                    ->nullable(),
                Select::make('status')
                    ->options(['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'excused' => 'Excused'])
                    ->default('present')
                    ->required(),
                Textarea::make('note')
                    ->columnSpanFull(),
            ]);
    }
}
