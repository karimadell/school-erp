<?php

namespace App\Filament\Resources\Curricula\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class CurriculumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('academic_year_id')
                ->label(__('curriculum.academic_year'))
                ->relationship('academicYear', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(
                    table: 'curricula',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('grade_id', $get('grade_id'))
                        ->where('subject_id', $get('subject_id'))
                )
                ->validationMessages([
                    'unique' => __('curriculum.duplicate'),
                ]),

            Select::make('grade_id')
                ->label(__('curriculum.grade'))
                ->relationship('grade', 'name')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('subject_id')
                ->label(__('curriculum.subject'))
                ->relationship('subject', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('weekly_hours')
                ->label(__('curriculum.weekly_hours'))
                ->numeric()
                ->minValue(1)
                ->required(),

            Select::make('type')
                ->label(__('curriculum.type'))
                ->options([
                    'mandatory' => __('curriculum.type_mandatory'),
                    'elective' => __('curriculum.type_elective'),
                    'optional_enrichment' => __('curriculum.type_optional_enrichment'),
                ])
                ->required(),

        ]);
    }
}
