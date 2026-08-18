<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Support\TimetableSlot;
use App\Support\WorkingDays;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extracted verbatim from ClassResource\Pages\TimetableGrid::generateTimetable()
 * so the dashboard timetable surface (App\Http\Controllers\Dashboard\ClassTimetableController)
 * and the Filament grid share the one canonical generation algorithm instead of
 * each keeping its own copy. Behavior is unchanged — see
 * tests/Feature/TimetableGridGenerateTest.php, which exercises this indirectly
 * through TimetableGrid::generateTimetable() and must keep passing unmodified.
 */
class TimetableGenerationService
{
    public function __construct(
        private readonly CurriculumAwareTimetableConflictChecker $conflictChecker,
        private readonly WorkingDays $workingDays,
    ) {}

    /**
     * @param  Collection  $days  Day models
     * @param  Collection  $periods  Period models
     * @return string|null translation key of the failure reason, or null on success
     */
    public function generate(int $classId, Collection $days, Collection $periods): ?string
    {
        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        if (! $activeYearId) {
            return 'timetable.generation_no_active_year';
        }

        $class = SchoolClass::find($classId);

        if (! $class || ! $class->is_active || ! $class->grade_id) {
            return 'timetable.generation_inactive_class';
        }

        $workingDays = $this->workingDays->workingDays($days);
        $periods = collect($periods);

        $curricula = Curriculum::with('subject')
            ->where('academic_year_id', $activeYearId)
            ->where('grade_id', $class->grade_id)
            ->where('is_active', true)
            ->get();

        if ($curricula->isEmpty()) {
            return 'timetable.generation_no_curriculum';
        }

        if ($curricula->contains(fn (Curriculum $curriculum) => ! $curriculum->subject?->is_active)) {
            return 'timetable.generation_inactive_subject';
        }

        $teachersBySubject = collect();

        foreach ($curricula as $curriculum) {
            $teachers = Teacher::where('is_active', true)
                ->whereHas('assignments', function ($query) use ($activeYearId, $class, $curriculum) {
                    $query->where('academic_year_id', $activeYearId)
                        ->where('class_id', $class->id)
                        ->where('subject_id', $curriculum->subject_id);
                })
                ->get();

            if ($teachers->isEmpty()) {
                return 'timetable.generation_missing_teacher';
            }

            $teachersBySubject->put($curriculum->subject_id, $teachers);
        }

        $requiredHours = (int) $curricula->sum('weekly_hours');
        $availableSlots = $workingDays->count() * $periods->count();

        if ($requiredHours > $availableSlots) {
            return 'timetable.generation_insufficient_slots';
        }

        $pool = [];

        foreach ($curricula as $curriculum) {
            for ($i = 0; $i < (int) $curriculum->weekly_hours; $i++) {
                $pool[] = $curriculum->subject;
            }
        }

        shuffle($pool);

        try {
            DB::transaction(function () use ($class, $workingDays, $periods, $teachersBySubject, &$pool) {
                Timetable::where('class_id', $class->id)->delete();

                $lastSubjectId = null;

                foreach ($workingDays as $day) {
                    $usedToday = [];

                    foreach ($periods as $period) {
                        if (empty($pool)) {
                            break 2;
                        }

                        $preferredSubjects = collect($pool)
                            ->where('id', '!=', $lastSubjectId)
                            ->whereNotIn('id', $usedToday);

                        $candidateSubjects = $preferredSubjects
                            ->concat($pool)
                            ->unique('id');

                        foreach ($candidateSubjects as $subject) {
                            foreach ($teachersBySubject->get($subject->id)->shuffle() as $teacher) {
                                $slot = new TimetableSlot(
                                    classId: $class->id,
                                    dayId: $day->id,
                                    periodId: $period->id,
                                    teacherId: $teacher->id,
                                    subjectId: $subject->id,
                                );

                                if ($this->conflictChecker->check($slot)->hasConflict()) {
                                    continue;
                                }

                                Timetable::create([
                                    'class_id' => $class->id,
                                    'day_id' => $day->id,
                                    'period_id' => $period->id,
                                    'subject_id' => $subject->id,
                                    'teacher_id' => $teacher->id,
                                ]);

                                $lastSubjectId = $subject->id;
                                $usedToday[] = $subject->id;

                                foreach ($pool as $index => $poolSubject) {
                                    if ($poolSubject->id === $subject->id) {
                                        unset($pool[$index]);
                                        $pool = array_values($pool);
                                        break;
                                    }
                                }

                                break 2;
                            }
                        }
                    }
                }

                if (! empty($pool)) {
                    throw new \RuntimeException('Unable to place every required curriculum lesson.');
                }
            });
        } catch (Throwable) {
            return 'timetable.generation_incomplete';
        }

        return null;
    }
}
