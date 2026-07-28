<?php

namespace App\Filament\Resources\LessonJournalEntries\Schemas;

use App\Models\Teacher;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LessonJournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('teacher_id')
                ->label('Учитель')
                ->relationship('teacher', 'last_name')
                ->getOptionLabelFromRecordUsing(fn (Teacher $record) => $record->full_name)
                ->searchable(['first_name', 'last_name'])
                ->preload()
                ->required(),

            Select::make('class_id')
                ->label('Класс')
                ->relationship('schoolClass', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('subject_id')
                ->label('Предмет')
                ->relationship('subject', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('academic_year_id')
                ->label('Учебный год')
                ->relationship('academicYear', 'name')
                ->searchable()
                ->preload()
                ->required(),

            DatePicker::make('date')
                ->label('Дата')
                ->required(),

            TextInput::make('title')
                ->label('Тема урока')
                ->required(),

            Textarea::make('notes')
                ->label('Заметки к уроку'),

            Textarea::make('homework')
                ->label('Домашнее задание'),

        ]);
    }
}
