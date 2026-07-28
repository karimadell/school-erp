<?php

namespace App\Filament\Resources\Curricula\RelationManagers;

use App\Models\Student;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Batch 11 / C2: which students have individually elected this
 * Curriculum row (elective / optional-enrichment only — rejected at the
 * observer level for mandatory rows). Authorization comes from
 * StudentSubjectEnrollmentPolicy (gated by 'manage curriculum'), not a
 * separate permission.
 */
class StudentSubjectEnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'studentSubjectEnrollments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('student_id')
                ->label(__('student_subject_enrollments.student'))
                ->relationship('student', 'name')
                ->getOptionLabelFromRecordUsing(fn (Student $record) => $record->full_name)
                ->searchable(['name', 'first_name_ru', 'last_name_ru'])
                ->preload()
                ->required()
                ->unique(
                    table: 'student_subject_enrollments',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule
                        ->where('curriculum_id', $this->getOwnerRecord()->id)
                )
                ->validationMessages([
                    'unique' => __('student_subject_enrollments.duplicate'),
                ]),

        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([

                TextColumn::make('student.full_name')
                    ->label(__('student_subject_enrollments.student'))
                    ->searchable(['student.name', 'student.first_name_ru', 'student.last_name_ru']),

            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
