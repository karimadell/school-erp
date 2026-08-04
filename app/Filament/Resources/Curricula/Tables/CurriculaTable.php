<?php

namespace App\Filament\Resources\Curricula\Tables;

use App\Models\Grade;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CurriculaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('academicYear.name')
                    ->label(__('curriculum.academic_year'))
                    ->sortable(),

                TextColumn::make('grade.name')
                    ->label(__('curriculum.grade'))
                    ->sortable(),

                TextColumn::make('subject.name_ru')
                    ->label(__('curriculum.subject'))
                    ->sortable(),

                TextColumn::make('weekly_hours')
                    ->label(__('curriculum.weekly_hours'))
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('curriculum.type'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'mandatory' => __('curriculum.type_mandatory'),
                        'elective' => __('curriculum.type_elective'),
                        'optional_enrichment' => __('curriculum.type_optional_enrichment'),
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('assessment_type')
                    ->label(__('curriculum.assessment_type'))
                    ->formatStateUsing(fn (string $state) => __('curriculum.assessment_' . $state))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('curriculum.is_active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([

                SelectFilter::make('academic_year_id')
                    ->label(__('curriculum.academic_year'))
                    ->relationship('academicYear', 'name'),

                SelectFilter::make('grade_id')
                    ->label(__('curriculum.grade'))
                    ->options(fn () => Grade::ordered()->pluck('name', 'id')),

                SelectFilter::make('type')
                    ->label(__('curriculum.type'))
                    ->options([
                        'mandatory' => __('curriculum.type_mandatory'),
                        'elective' => __('curriculum.type_elective'),
                        'optional_enrichment' => __('curriculum.type_optional_enrichment'),
                    ]),

            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
