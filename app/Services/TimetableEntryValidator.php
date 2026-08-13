<?php

namespace App\Services;

use App\Models\Academic\Timetable as AcademicTimetable;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\Curriculum;
use App\Models\PhysicalClassroom;
use App\Models\SchoolClass;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Illuminate\Validation\ValidationException;

class TimetableEntryValidator
{
    public function validate(TimetableEntry $entry, bool $requireComplete = true): void
    {
        $version = TimetableVersion::find($entry->timetable_version_id);

        if (! $version || $version->status !== TimetableVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'timetable_version_id' => __('timetable_entry.validation.draft_only'),
            ]);
        }

        if ($requireComplete) {
            $this->validateRequiredReferences($entry);
        }

        $this->validateStructuralReferences($entry, $version);
        $this->validateCurriculumAndAssignment($entry, $version);
        $this->validateConflicts($entry);
    }

    private function validateRequiredReferences(TimetableEntry $entry): void
    {
        foreach ([
            'timetable_version_id', 'academic_timetable_id', 'weekday', 'bell_schedule_id',
            'bell_schedule_period_id', 'classroom_id', 'teacher_assignment_id',
            'curriculum_id', 'subject_id', 'class_id',
        ] as $field) {
            if (blank($entry->{$field})) {
                throw ValidationException::withMessages([
                    $field => __('timetable_entry.validation.required_reference'),
                ]);
            }
        }
    }

    private function validateStructuralReferences(TimetableEntry $entry, TimetableVersion $version): void
    {
        if (($entry->weekday ?? 0) < 1 || $entry->weekday > 7) {
            throw ValidationException::withMessages(['weekday' => __('timetable_entry.validation.invalid_weekday')]);
        }

        $header = AcademicTimetable::find($entry->academic_timetable_id);
        if ($header && $header->timetable_version_id !== $version->id) {
            throw ValidationException::withMessages([
                'academic_timetable_id' => __('timetable_entry.validation.header_version_mismatch'),
            ]);
        }

        $schedule = BellSchedule::find($entry->bell_schedule_id);
        if ($schedule && $schedule->academic_year_id !== $version->academic_year_id) {
            throw ValidationException::withMessages([
                'bell_schedule_id' => __('timetable_entry.validation.cross_year_schedule'),
            ]);
        }

        $period = BellSchedulePeriod::find($entry->bell_schedule_period_id);
        if ($period && $period->bell_schedule_id !== $entry->bell_schedule_id) {
            throw ValidationException::withMessages([
                'bell_schedule_period_id' => __('timetable_entry.validation.period_schedule_mismatch'),
            ]);
        }

        $classroom = PhysicalClassroom::find($entry->classroom_id);
        if ($classroom && $classroom->academic_year_id !== $version->academic_year_id) {
            throw ValidationException::withMessages([
                'classroom_id' => __('timetable_entry.validation.cross_year_classroom'),
            ]);
        }
        if ($classroom && ! $classroom->is_active) {
            throw ValidationException::withMessages([
                'classroom_id' => __('timetable_entry.validation.inactive_classroom'),
            ]);
        }
    }

    private function validateCurriculumAndAssignment(TimetableEntry $entry, TimetableVersion $version): void
    {
        $curriculum = Curriculum::find($entry->curriculum_id);
        $schoolClass = SchoolClass::find($entry->class_id);
        $assignment = TeacherAssignment::find($entry->teacher_assignment_id);

        if ($curriculum && $curriculum->academic_year_id !== $version->academic_year_id) {
            throw ValidationException::withMessages(['curriculum_id' => __('timetable_entry.validation.cross_year_curriculum')]);
        }
        if ($curriculum && $curriculum->subject_id !== $entry->subject_id) {
            throw ValidationException::withMessages(['subject_id' => __('timetable_entry.validation.subject_curriculum_mismatch')]);
        }
        if ($curriculum && $schoolClass && $curriculum->grade_id !== $schoolClass->grade_id) {
            throw ValidationException::withMessages(['class_id' => __('timetable_entry.validation.class_curriculum_mismatch')]);
        }

        if ($assignment && $assignment->academic_year_id !== $version->academic_year_id) {
            throw ValidationException::withMessages(['teacher_assignment_id' => __('timetable_entry.validation.cross_year_assignment')]);
        }
        if ($assignment && ($assignment->subject_id !== $entry->subject_id || $assignment->class_id !== $entry->class_id)) {
            throw ValidationException::withMessages(['teacher_assignment_id' => __('timetable_entry.validation.assignment_mismatch')]);
        }
    }

    private function validateConflicts(TimetableEntry $entry): void
    {
        $slot = TimetableEntry::query()
            ->where('timetable_version_id', $entry->timetable_version_id)
            ->where('weekday', $entry->weekday)
            ->where('bell_schedule_period_id', $entry->bell_schedule_period_id)
            ->when($entry->exists, fn ($query) => $query->whereKeyNot($entry->getKey()));

        if ((clone $slot)->where('class_id', $entry->class_id)
            ->where('subject_id', $entry->subject_id)
            ->where('teacher_assignment_id', $entry->teacher_assignment_id)
            ->where('classroom_id', $entry->classroom_id)->exists()) {
            throw ValidationException::withMessages(['entry' => __('timetable_entry.validation.duplicate_entry')]);
        }

        $assignment = TeacherAssignment::find($entry->teacher_assignment_id);
        if ($assignment && (clone $slot)->whereHas('teacherAssignment', fn ($query) => $query
            ->where('teacher_id', $assignment->teacher_id))->exists()) {
            throw ValidationException::withMessages(['teacher_assignment_id' => __('timetable_entry.validation.teacher_conflict')]);
        }
        if ((clone $slot)->where('class_id', $entry->class_id)->exists()) {
            throw ValidationException::withMessages(['class_id' => __('timetable_entry.validation.class_conflict')]);
        }
        if ((clone $slot)->where('classroom_id', $entry->classroom_id)->exists()) {
            throw ValidationException::withMessages(['classroom_id' => __('timetable_entry.validation.classroom_conflict')]);
        }
    }
}
