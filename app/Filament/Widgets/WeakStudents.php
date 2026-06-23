<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

use App\Models\Student;

class WeakStudents extends TableWidget
{
    protected static ?string $heading = 'Weak Students';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Student::query()
                    ->with('class')
                    ->withAvg('grades', 'score')
                    ->having('grades_avg_score', '<', 3)
                    ->orderBy('grades_avg_score')
                    ->limit(10)
            )
            ->columns([

                TextColumn::make('full_name')
                    ->label('Student'),

                TextColumn::make('class.name')
                    ->label('Class'),

                TextColumn::make('grades_avg_score')
                    ->label('Average Grade')
                    ->numeric(2)
                    ->color('danger'),

            ]);
    }
}