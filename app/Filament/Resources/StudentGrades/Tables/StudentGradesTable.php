<?php

namespace App\Filament\Resources\StudentGrades\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class StudentGradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('student.first_name')
                    ->label('Student')
                    ->searchable(),

                TextColumn::make('subject.name_ru')
                    ->label('Subject'),

                TextColumn::make('exam.name')
                    ->label('Exam'),

                TextColumn::make('quarter.name')
                    ->label('Quarter'),

                TextColumn::make('score')
                    ->label('Score'),

                TextColumn::make('note')
                    ->label('Note'),

            ]);
    }
}