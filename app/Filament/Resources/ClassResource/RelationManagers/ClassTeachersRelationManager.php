<?php

namespace App\Filament\Resources\ClassResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Batch 11 / C4: Class Teacher assignment (классный руководитель),
 * managed directly on the class it belongs to. One homeroom teacher per
 * class per academic year — the same teacher may be homeroom for
 * multiple different classes. Authorization comes from ClassTeacherPolicy
 * (gated by 'manage classes'), not a SchoolClassPolicy — that remains
 * explicitly out of scope for this item.
 */
class ClassTeachersRelationManager extends RelationManager
{
    protected static string $relationship = 'classTeachers';

    public function form(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('teacher_id')
                ->label(__('class_teachers.teacher'))
                ->relationship('teacher', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                ->searchable(['first_name', 'last_name'])
                ->preload()
                ->required(),

            Select::make('academic_year_id')
                ->label(__('class_teachers.academic_year'))
                ->relationship('academicYear', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(
                    table: 'class_teachers',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule
                        ->where('school_class_id', $this->getOwnerRecord()->id)
                )
                ->validationMessages([
                    'unique' => __('class_teachers.duplicate'),
                ]),

        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([

                TextColumn::make('teacher.full_name')
                    ->label(__('class_teachers.teacher'))
                    ->searchable(['teacher.first_name', 'teacher.last_name']),

                TextColumn::make('academicYear.name')
                    ->label(__('class_teachers.academic_year'))
                    ->sortable(),

            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
