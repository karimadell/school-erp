<?php

namespace App\Filament\Resources\Timetables\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TimetableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('class_id')
                    ->required()
                    ->numeric(),
                TextInput::make('subject_id')
                    ->required()
                    ->numeric(),
                TextInput::make('teacher_id')
                    ->required()
                    ->numeric(),
                TextInput::make('day_id')
                    ->required()
                    ->numeric(),
                TextInput::make('period_id')
                    ->required()
                    ->numeric(),
            ]);
    }
}
