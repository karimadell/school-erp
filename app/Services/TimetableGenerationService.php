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
 *
 * Placement algorithm (Phase 5A): most-constrained-first backtracking, not a
 * single-pass greedy pool. A single pass can "starve" a tightly-booked
 * teacher out of their only remaining valid slot when a more flexible
 * subject happens to get tried first — a real UAT class with two subjects
 * whose teachers each had very little spare capacity elsewhere failed
 * unpredictably even though a complete schedule existed. Ordering subjects
 * by each requirement's current valid domain, and backtracking (undoing a
 * placement and trying the next slot) instead of accepting the first success
 * unconditionally, finds constrained schedules the greedy pass missed. Every
 * candidate slot is still validated through the exact same
 * CurriculumAwareTimetableConflictChecker rule chain as before — this
 * change is to search *order*, not to any conflict rule.
 */
class TimetableGenerationService
{
    /**
     * Hard node budget for pathological inputs. Normal 5 × 6 timetables take
     * one node per lesson when MRV finds a direct path; the constrained UAT
     * regression remains far below this ceiling. The existing domain only
     * has one public incomplete-result key, so exhaustion intentionally uses
     * the same generation_incomplete response as an unsatisfiable search.
     */
    private const MAX_BACKTRACK_STEPS = 100000;

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

        $requirements = $curricula->mapWithKeys(fn (Curriculum $curriculum) => [
            $curriculum->subject_id => [
                'subject' => $curriculum->subject,
                'remaining' => (int) $curriculum->weekly_hours,
            ],
        ])->all();

        try {
            DB::transaction(function () use ($class, $workingDays, $periods, $teachersBySubject, $requirements) {
                Timetable::where('class_id', $class->id)->delete();

                $candidateDomains = [];
                foreach ($requirements as $subjectId => $requirement) {
                    $candidateDomains[$subjectId] = $this->validCandidates(
                        $class->id,
                        (int) $subjectId,
                        $workingDays,
                        $periods,
                        $teachersBySubject->get($subjectId),
                    );
                }

                $steps = 0;

                if (! $this->placeRequirements($class->id, $requirements, $candidateDomains, [], [], $steps)) {
                    throw new \RuntimeException('Unable to place every required curriculum lesson.');
                }
            });
        } catch (Throwable) {
            return 'timetable.generation_incomplete';
        }

        return null;
    }

    /**
     * Dynamic MRV backtracking. Rebuild every remaining subject's valid
     * domain after each placement, reject a subject that no longer has enough
     * distinct class slots, and choose the subject with the smallest slack
     * (valid slots minus required lessons). Candidate slots needed by fewer
     * other subjects are tried first so scarce shared slots are preserved.
     *
     * @param  array<int, array{subject: mixed, remaining: int}>  $requirements
     * @param  array<int, array<int, array{day_id: int, period_id: int, teacher_id: int, slot_key: string, teacher_slot_key: string, day_order: int, period_order: int}>>  $candidateDomains
     * @param  array<string, true>  $occupiedClassSlots
     * @param  array<string, true>  $occupiedTeacherSlots
     */
    private function placeRequirements(
        int $classId,
        array $requirements,
        array $candidateDomains,
        array $occupiedClassSlots,
        array $occupiedTeacherSlots,
        int &$steps,
    ): bool {
        $remainingTotal = array_sum(array_column($requirements, 'remaining'));

        if ($remainingTotal === 0) {
            return true;
        }

        if (++$steps > self::MAX_BACKTRACK_STEPS) {
            return false;
        }

        $domains = [];
        $allCandidateSlots = [];

        foreach ($requirements as $subjectId => $requirement) {
            if ($requirement['remaining'] === 0) {
                continue;
            }

            $candidates = array_values(array_filter(
                $candidateDomains[$subjectId],
                fn (array $candidate): bool => ! isset($occupiedClassSlots[$candidate['slot_key']])
                    && ! isset($occupiedTeacherSlots[$candidate['teacher_slot_key']]),
            ));
            $uniqueSlotCount = count(array_unique(array_column($candidates, 'slot_key')));

            if ($uniqueSlotCount < $requirement['remaining']) {
                return false;
            }

            foreach ($candidates as $candidate) {
                $allCandidateSlots[$candidate['slot_key']] = true;
            }

            $domains[$subjectId] = [
                'candidates' => $candidates,
                'unique_slots' => $uniqueSlotCount,
                'remaining' => $requirement['remaining'],
            ];
        }

        if (count($allCandidateSlots) < $remainingTotal) {
            return false;
        }

        uksort($domains, function (int|string $leftId, int|string $rightId) use ($domains): int {
            $left = $domains[$leftId];
            $right = $domains[$rightId];

            return ($left['unique_slots'] - $left['remaining']) <=> ($right['unique_slots'] - $right['remaining'])
                ?: $left['unique_slots'] <=> $right['unique_slots']
                ?: (int) $leftId <=> (int) $rightId;
        });

        $subjectId = (int) array_key_first($domains);
        $slotContention = [];

        foreach ($domains as $domain) {
            foreach (array_unique(array_column($domain['candidates'], 'slot_key')) as $slotKey) {
                $slotContention[$slotKey] = ($slotContention[$slotKey] ?? 0) + 1;
            }
        }

        $candidates = $domains[$subjectId]['candidates'];
        usort($candidates, fn (array $left, array $right): int => $slotContention[$left['slot_key']] <=> $slotContention[$right['slot_key']]
                ?: $left['day_order'] <=> $right['day_order']
                ?: $left['period_order'] <=> $right['period_order']
                ?: $left['teacher_id'] <=> $right['teacher_id']
        );

        foreach ($candidates as $candidate) {
            $slot = new TimetableSlot(
                classId: $classId,
                dayId: $candidate['day_id'],
                periodId: $candidate['period_id'],
                teacherId: $candidate['teacher_id'],
                subjectId: $subjectId,
            );

            if ($this->conflictChecker->check($slot)->hasConflict()) {
                continue;
            }

            $lesson = Timetable::create([
                'class_id' => $classId,
                'day_id' => $candidate['day_id'],
                'period_id' => $candidate['period_id'],
                'subject_id' => $subjectId,
                'teacher_id' => $candidate['teacher_id'],
            ]);

            $requirements[$subjectId]['remaining']--;
            $occupiedClassSlots[$candidate['slot_key']] = true;
            $occupiedTeacherSlots[$candidate['teacher_slot_key']] = true;

            if ($this->placeRequirements(
                $classId,
                $requirements,
                $candidateDomains,
                $occupiedClassSlots,
                $occupiedTeacherSlots,
                $steps,
            )) {
                return true;
            }

            $lesson->delete();
            $requirements[$subjectId]['remaining']++;
            unset($occupiedClassSlots[$candidate['slot_key']], $occupiedTeacherSlots[$candidate['teacher_slot_key']]);
        }

        return false;
    }

    /**
     * Return deterministic, conflict-checked (day, period, teacher) choices.
     * The canonical checker remains the source of truth for every rule.
     *
     * @return array<int, array{day_id: int, period_id: int, teacher_id: int, slot_key: string, teacher_slot_key: string, day_order: int, period_order: int}>
     */
    private function validCandidates(
        int $classId,
        int $subjectId,
        Collection $workingDays,
        Collection $periods,
        Collection $teachers,
    ): array {
        $candidates = [];

        foreach ($workingDays->values() as $dayOrder => $day) {
            foreach ($periods->values() as $periodOrder => $period) {
                foreach ($teachers->sortBy('id') as $teacher) {
                    $slot = new TimetableSlot(
                        classId: $classId,
                        dayId: $day->id,
                        periodId: $period->id,
                        teacherId: $teacher->id,
                        subjectId: $subjectId,
                    );

                    if ($this->conflictChecker->check($slot)->hasConflict()) {
                        continue;
                    }

                    $candidates[] = [
                        'day_id' => $day->id,
                        'period_id' => $period->id,
                        'teacher_id' => $teacher->id,
                        'slot_key' => $day->id.'-'.$period->id,
                        'teacher_slot_key' => $teacher->id.'-'.$day->id.'-'.$period->id,
                        'day_order' => $dayOrder,
                        'period_order' => $periodOrder,
                    ];
                }
            }
        }

        return $candidates;
    }
}
