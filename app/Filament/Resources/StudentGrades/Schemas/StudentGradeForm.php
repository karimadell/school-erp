<?php

namespace App\Filament\Resources\StudentGrades\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class StudentGradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('student_id')
                ->relationship('student', 'first_name')
                ->searchable()
                ->required(),

            Select::make('subject_id')
                ->relationship('subject', 'name_ru')
                ->required(),

            Select::make('exam_id')
                ->relationship('exam', 'name')
                ->required(),

            Select::make('quarter_id')
                ->relationship('quarter', 'name')
                ->required(),

            TextInput::make('score')
                ->numeric()
                ->required(),

            TextInput::make('note')
                ->label('Note')

        ]);
    }
}