<?php

namespace App\Filament\Resources\TimetableVersions\RelationManagers;

use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\Curriculum;
use App\Models\PhysicalClassroom;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\TimetableEntryService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('timetable_entry.title');
    }

    public function form(Schema $schema): Schema
    {
        $yearId = $this->getOwnerRecord()->academic_year_id;

        return $schema->components([
            Select::make('weekday')->label(__('timetable_entry.fields.weekday'))
                ->options(__('timetable_entry.weekdays'))->required(),
            Select::make('bell_schedule_id')->label(__('timetable_entry.fields.bell_schedule'))
                ->options(BellSchedule::query()->where('academic_year_id', $yearId)->where('is_active', true)
                    ->orderBy('shift')->orderBy('name')->pluck('name', 'id'))
                ->live()->afterStateUpdated(fn ($set) => $set('bell_schedule_period_id', null))
                ->searchable()->preload()->required(),
            Select::make('bell_schedule_period_id')->label(__('timetable_entry.fields.period'))
                ->options(fn (Get $get) => BellSchedulePeriod::query()
                    ->where('bell_schedule_id', $get('bell_schedule_id'))->where('is_active', true)
                    ->orderBy('period_number')->get()
                    ->mapWithKeys(fn ($period) => [$period->id => $period->period_number.' — '.($period->label ?: $period->starts_at.'–'.$period->ends_at)]))
                ->searchable()->preload()->required(),
            Select::make('class_id')->label(__('timetable_entry.fields.class'))
                ->options(SchoolClass::query()->where('is_active', true)->orderBy('code')->pluck('name_ru', 'id'))
                ->live()->afterStateUpdated(function ($set): void {
                    $set('subject_id', null);
                    $set('teacher_assignment_id', null);
                })->searchable()->preload()->required(),
            Select::make('subject_id')->label(__('timetable_entry.fields.subject'))
                ->options(function (Get $get) use ($yearId) {
                    $gradeId = SchoolClass::find($get('class_id'))?->grade_id;

                    return Subject::query()->whereIn('id', Curriculum::query()
                        ->where('academic_year_id', $yearId)->where('grade_id', $gradeId)
                        ->where('is_active', true)->select('subject_id'))
                        ->orderBy('name_ru')->pluck('name_ru', 'id');
                })->live()->afterStateUpdated(fn ($set) => $set('teacher_assignment_id', null))
                ->searchable()->preload()->required(),
            Select::make('teacher_assignment_id')->label(__('timetable_entry.fields.teacher'))
                ->options(fn (Get $get) => TeacherAssignment::query()
                    ->with('teacher')->where('academic_year_id', $yearId)
                    ->where('class_id', $get('class_id'))->where('subject_id', $get('subject_id'))
                    ->get()->mapWithKeys(fn ($assignment) => [$assignment->id => $assignment->teacher->full_name]))
                ->searchable()->preload()->required(),
            Select::make('classroom_id')->label(__('timetable_entry.fields.classroom'))
                ->options(PhysicalClassroom::query()->where('academic_year_id', $yearId)->where('is_active', true)
                    ->orderBy('building')->orderBy('floor')->orderBy('code')
                    ->get()->mapWithKeys(fn ($room) => [$room->id => $room->code.' — '.$room->name]))
                ->searchable()->preload()->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $isDraft = $owner->status === TimetableVersion::STATUS_DRAFT;

        return $table->columns([
            TextColumn::make('weekday')->label(__('timetable_entry.fields.weekday'))
                ->formatStateUsing(fn ($state) => __('timetable_entry.weekdays')[$state] ?? $state)->sortable(),
            TextColumn::make('period.period_number')->label(__('timetable_entry.fields.period'))->sortable(),
            TextColumn::make('schoolClass.name_ru')->label(__('timetable_entry.fields.class'))->searchable(),
            TextColumn::make('subject.name_ru')->label(__('timetable_entry.fields.subject'))->searchable(),
            TextColumn::make('teacherAssignment.teacher.full_name')->label(__('timetable_entry.fields.teacher'))->searchable(),
            TextColumn::make('classroom.code')->label(__('timetable_entry.fields.classroom'))->searchable(),
        ])->defaultSort('weekday')
            ->filters([
                SelectFilter::make('weekday')->label(__('timetable_entry.filters.weekday'))
                    ->options(__('timetable_entry.weekdays')),
                SelectFilter::make('class_id')->label(__('timetable_entry.filters.class'))
                    ->options(SchoolClass::query()->orderBy('code')->pluck('name_ru', 'id')),
                SelectFilter::make('teacher_id')->label(__('timetable_entry.filters.teacher'))
                    ->options(Teacher::query()->whereHas('assignments', fn ($query) => $query
                        ->where('academic_year_id', $owner->academic_year_id))
                        ->get()->mapWithKeys(fn ($teacher) => [$teacher->id => $teacher->full_name]))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($query, $teacherId) => $query->whereHas('teacherAssignment', fn ($assignment) => $assignment
                            ->where('teacher_id', $teacherId)),
                    )),
            ])
            ->headerActions([
                CreateAction::make()->visible($isDraft)
                    ->using(function (array $data) use ($owner): TimetableEntry {
                        $header = $owner->timetables()->firstOrCreate(['name' => $owner->name]);
                        $data['timetable_version_id'] = $owner->id;
                        $data['academic_timetable_id'] = $header->id;

                        return app(TimetableEntryService::class)->create($data);
                    }),
            ])
            ->recordActions([
                EditAction::make()->visible($isDraft)
                    ->using(fn (TimetableEntry $record, array $data) => app(TimetableEntryService::class)->update($record, $data)),
                DeleteAction::make()->visible($isDraft)
                    ->using(fn (TimetableEntry $record) => app(TimetableEntryService::class)->delete($record)),
            ]);
    }
}
