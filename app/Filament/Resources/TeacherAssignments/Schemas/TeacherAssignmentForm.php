<?php

namespace App\Filament\Resources\TeacherAssignments\Schemas;

use App\Models\Teacher;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class TeacherAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('teacher_id')
                ->label(__('teacher_assignment.fields.teacher'))
                ->relationship('teacher', 'last_name')
                ->getOptionLabelFromRecordUsing(fn (Teacher $record) => $record->full_name)
                ->searchable(['first_name', 'last_name'])
                ->preload()
                ->required(),

            Select::make('class_id')
                ->label(__('teacher_assignment.fields.class'))
                ->relationship('schoolClass', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('subject_id')
                ->label(__('teacher_assignment.fields.subject'))
                ->relationship('subject', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('academic_year_id')
                ->label(__('teacher_assignment.fields.academic_year'))
                ->relationship('academicYear', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(
                    table: 'teacher_assignments',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('teacher_id', $get('teacher_id'))
                        ->where('class_id', $get('class_id'))
                        ->where('subject_id', $get('subject_id'))
                )
                ->validationMessages([
                    'unique' => __('teacher_assignment.validation.duplicate'),
                ]),

        ]);
    }
}
