<?php

namespace App\Filament\Resources\Classrooms\Tables;

use App\Models\PhysicalClassroom;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClassroomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'erp-resource-table erp-classrooms-table'])
            ->searchPlaceholder(__('classroom.search_placeholder'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('classroom.fields.code'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('classroom.fields.name'))
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('room_type')
                    ->label(__('classroom.fields.room_type'))
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state) => __("classroom.types.{$state}")),

                TextColumn::make('building')
                    ->label(__('classroom.fields.building'))
                    ->searchable()
                    ->visibleFrom('sm'),

                TextColumn::make('floor')
                    ->label(__('classroom.fields.floor'))
                    ->visibleFrom('md')
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label(__('classroom.fields.capacity'))
                    ->alignEnd()
                    ->visibleFrom('sm')
                    ->sortable(),

                TextColumn::make('academicYear.name')
                    ->label(__('classroom.fields.academic_year'))
                    ->visibleFrom('lg')
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label(__('classroom.fields.is_active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('classroom.active') : __('classroom.inactive'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('classroom.fields.academic_year'))
                    ->relationship('academicYear', 'name'),

                SelectFilter::make('room_type')
                    ->label(__('classroom.fields.room_type'))
                    ->options(collect(PhysicalClassroom::TYPES)
                        ->mapWithKeys(fn (string $type): array => [$type => __("classroom.types.{$type}")])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label(__('classroom.fields.is_active'))
                    ->trueLabel(__('classroom.active'))
                    ->falseLabel(__('classroom.inactive')),
            ])
            ->filtersFormColumns(1)
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip(__('filament-actions::edit.single.label')),
            ]);
    }
}
