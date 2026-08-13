<?php

namespace App\Filament\Resources\Classrooms\Schemas;

use App\Models\PhysicalClassroom;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ClassroomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_year_id')
                ->label(__('classroom.fields.academic_year'))
                ->relationship('academicYear', 'name')
                ->searchable()->preload()->required(),
            TextInput::make('building')->label(__('classroom.fields.building'))->maxLength(255),
            TextInput::make('floor')->label(__('classroom.fields.floor'))->maxLength(255),
            TextInput::make('code')->label(__('classroom.fields.code'))->required()->maxLength(255)
                ->unique(
                    table: 'classrooms',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('academic_year_id', $get('academic_year_id')),
                )
                ->validationMessages(['unique' => __('classroom.validation.duplicate_code')]),
            TextInput::make('name')->label(__('classroom.fields.name'))->required()->maxLength(255),
            TextInput::make('capacity')->label(__('classroom.fields.capacity'))
                ->numeric()->integer()->minValue(1)->required(),
            Select::make('room_type')->label(__('classroom.fields.room_type'))
                ->options(collect(PhysicalClassroom::TYPES)->mapWithKeys(
                    fn ($type) => [$type => __("classroom.types.{$type}")],
                )->all())
                ->required(),
            Toggle::make('is_active')->label(__('classroom.fields.is_active'))->default(true),
            Textarea::make('notes')->label(__('classroom.fields.notes'))->columnSpanFull(),
        ]);
    }
}
