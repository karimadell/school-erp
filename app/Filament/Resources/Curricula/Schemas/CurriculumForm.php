<?php

namespace App\Filament\Resources\Curricula\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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

            Select::make('assessment_type')
                ->label(__('curriculum.assessment_type'))
                ->options([
                    'grade' => __('curriculum.assessment_grade'),
                    'pass_fail' => __('curriculum.assessment_pass_fail'),
                    'ungraded' => __('curriculum.assessment_ungraded'),
                ])
                ->default('grade')
                ->required(),

            TextInput::make('report_order')
                ->label(__('curriculum.report_order'))
                ->numeric()
                ->minValue(1)
                ->maxValue(999),

            Toggle::make('is_active')
                ->label(__('curriculum.is_active'))
                ->default(true),

            Textarea::make('notes')
                ->label(__('curriculum.notes'))
                ->maxLength(2000),

        ]);
    }
}
