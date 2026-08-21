<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\SchoolClass;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use App\Support\TimetableGenerationTelemetry;
use App\Support\WorkingDays;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deterministic most-constrained-first timetable solver.
 *
 * All rule inputs are loaded before search. Recursive search is pure memory:
 * no conflict SQL and no tentative Eloquent writes. A completed solution is
 * bulk-persisted once; failure leaves the previous timetable unchanged.
 */
class TimetableGenerationService
{
    private const MAX_BACKTRACK_STEPS = 100000;

    private ?TimetableGenerationTelemetry $lastTelemetry = null;

    public function __construct(private readonly WorkingDays $workingDays) {}

    public function lastTelemetry(): ?TimetableGenerationTelemetry
    {
        return $this->lastTelemetry;
    }

    /**
     * @param  Collection  $days  Day models
     * @param  Collection  $periods  Legacy Period models
     * @return string|null translation key of the failure reason, or null on success
     */
    public function generate(int $classId, Collection $days, Collection $periods): ?string
    {
        $startedAt = hrtime(true);
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $queryOffset = $this->startQueryMeasurement($connection, $wasLogging);
        $nodes = 0;
        $backtracks = 0;
        $succeeded = false;

        try {
            $activeYearId = AcademicYear::where('is_active', true)->value('id');

            if (! $activeYearId) {
                return 'timetable.generation_no_active_year';
            }

            $class = SchoolClass::find($classId);

            if (! $class || ! $class->is_active || ! $class->grade_id) {
                return 'timetable.generation_inactive_class';
            }

            $workingDays = $this->workingDays->workingDays($days)->values();
            $periods = $this->applicablePeriods($periods);

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

            $assignments = TeacherAssignment::with('teacher')
                ->where('academic_year_id', $activeYearId)
                ->where('class_id', $class->id)
                ->whereIn('subject_id', $curricula->pluck('subject_id'))
                ->get()
                ->filter(fn (TeacherAssignment $assignment) => $assignment->teacher?->is_active)
                ->groupBy('subject_id');

            if ($curricula->contains(fn (Curriculum $curriculum) => $assignments->get($curriculum->subject_id, collect())->isEmpty())) {
                return 'timetable.generation_missing_teacher';
            }

            $requiredHours = (int) $curricula->sum('weekly_hours');

            if ($requiredHours > $workingDays->count() * $periods->count()) {
                return 'timetable.generation_insufficient_slots';
            }

            $requirements = $curricula->mapWithKeys(fn (Curriculum $curriculum) => [
                $curriculum->subject_id => [
                    'remaining' => (int) $curriculum->weekly_hours,
                ],
            ])->all();

            $teacherIds = $assignments->flatten()->pluck('teacher_id')->unique()->values();

            $failure = DB::transaction(function () use (
                $class,
                $workingDays,
                $periods,
                $assignments,
                $requirements,
                $teacherIds,
                &$nodes,
                &$backtracks,
                &$succeeded,
            ): ?string {
                // Lock the occupancy snapshot for the short in-memory search and
                // final replacement. The target class's old rows are intentionally
                // excluded: generation replaces them only after solving succeeds.
                $existing = Timetable::query()
                    ->where('class_id', '!=', $class->id)
                    ->whereIn('teacher_id', $teacherIds)
                    ->lockForUpdate()
                    ->get(['class_id', 'teacher_id', 'day_id', 'period_id']);

                $occupiedTeacherSlots = [];
                $occupiedClassSlots = [];

                foreach ($existing as $lesson) {
                    $occupiedTeacherSlots[$this->teacherSlotKey(
                        $lesson->teacher_id,
                        $lesson->day_id,
                        $lesson->period_id,
                    )] = true;
                    $occupiedClassSlots[$this->classSlotKey(
                        $lesson->class_id,
                        $lesson->day_id,
                        $lesson->period_id,
                    )] = true;
                }

                $candidateDomains = $this->candidateDomains(
                    $class->id,
                    $requirements,
                    $workingDays,
                    $periods,
                    $assignments,
                    $occupiedClassSlots,
                    $occupiedTeacherSlots,
                );
                $placements = [];
                $occupiedTargetClassSlots = [];

                if (! $this->placeRequirements(
                    $requirements,
                    $candidateDomains,
                    $occupiedTargetClassSlots,
                    $occupiedTeacherSlots,
                    $placements,
                    $nodes,
                    $backtracks,
                )) {
                    return 'timetable.generation_incomplete';
                }

                $now = now();
                $rows = array_map(fn (array $placement) => [
                    'class_id' => $class->id,
                    'day_id' => $placement['day_id'],
                    'period_id' => $placement['period_id'],
                    'subject_id' => $placement['subject_id'],
                    'teacher_id' => $placement['teacher_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $placements);

                Timetable::where('class_id', $class->id)->delete();
                DB::table('timetables')->insert($rows);
                $succeeded = true;

                return null;
            });

            return $failure;
        } catch (Throwable) {
            return 'timetable.generation_incomplete';
        } finally {
            $queryMetrics = $this->finishQueryMeasurement($connection, $wasLogging, $queryOffset);
            $this->lastTelemetry = new TimetableGenerationTelemetry(
                nodes: $nodes,
                backtracks: $backtracks,
                elapsedSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
                queryCount: $queryMetrics['count'],
                queryTimeMs: $queryMetrics['time_ms'],
                succeeded: $succeeded,
            );
        }
    }

    /**
     * The legacy timetable UI is a six-period workflow. Bell schedules belong
     * to the versioned timetable system and do not map their period IDs to the
     * legacy Period model, so the stable shared domain key is period number.
     */
    private function applicablePeriods(Collection $periods): Collection
    {
        return $periods
            ->filter(fn ($period) => (int) $period->number >= 1 && (int) $period->number <= 6)
            ->sortBy('number')
            ->values();
    }

    private function candidateDomains(
        int $classId,
        array $requirements,
        Collection $workingDays,
        Collection $periods,
        Collection $assignments,
        array $occupiedClassSlots,
        array $occupiedTeacherSlots,
    ): array {
        $domains = [];

        foreach ($requirements as $subjectId => $_requirement) {
            $domains[$subjectId] = [];

            foreach ($workingDays as $dayOrder => $day) {
                foreach ($periods as $periodOrder => $period) {
                    $classSlotKey = $this->classSlotKey($classId, $day->id, $period->id);

                    if (isset($occupiedClassSlots[$classSlotKey])) {
                        continue;
                    }

                    foreach ($assignments->get($subjectId)->sortBy('teacher_id') as $assignment) {
                        $teacherSlotKey = $this->teacherSlotKey($assignment->teacher_id, $day->id, $period->id);

                        if (isset($occupiedTeacherSlots[$teacherSlotKey])) {
                            continue;
                        }

                        $domains[$subjectId][] = [
                            'day_id' => $day->id,
                            'period_id' => $period->id,
                            'teacher_id' => $assignment->teacher_id,
                            'subject_id' => (int) $subjectId,
                            'slot_key' => $classSlotKey,
                            'teacher_slot_key' => $teacherSlotKey,
                            'day_order' => $dayOrder,
                            'period_order' => $periodOrder,
                        ];
                    }
                }
            }
        }

        return $domains;
    }

    private function placeRequirements(
        array &$requirements,
        array $candidateDomains,
        array &$occupiedTargetClassSlots,
        array &$occupiedTeacherSlots,
        array &$placements,
        int &$nodes,
        int &$backtracks,
    ): bool {
        if (array_sum(array_column($requirements, 'remaining')) === 0) {
            return true;
        }

        if (++$nodes > self::MAX_BACKTRACK_STEPS) {
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
                fn (array $candidate) => ! isset($occupiedTargetClassSlots[$candidate['slot_key']])
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

        if (count($allCandidateSlots) < array_sum(array_column($requirements, 'remaining'))) {
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
            $requirements[$subjectId]['remaining']--;
            $occupiedTargetClassSlots[$candidate['slot_key']] = true;
            $occupiedTeacherSlots[$candidate['teacher_slot_key']] = true;
            $placements[] = $candidate;

            if ($this->placeRequirements(
                $requirements,
                $candidateDomains,
                $occupiedTargetClassSlots,
                $occupiedTeacherSlots,
                $placements,
                $nodes,
                $backtracks,
            )) {
                return true;
            }

            array_pop($placements);
            $requirements[$subjectId]['remaining']++;
            unset($occupiedTargetClassSlots[$candidate['slot_key']], $occupiedTeacherSlots[$candidate['teacher_slot_key']]);
            $backtracks++;
        }

        return false;
    }

    private function teacherSlotKey(int $teacherId, int $dayId, int $periodId): string
    {
        return $teacherId.'-'.$dayId.'-'.$periodId;
    }

    private function classSlotKey(int $classId, int $dayId, int $periodId): string
    {
        return $classId.'-'.$dayId.'-'.$periodId;
    }

    private function startQueryMeasurement(Connection $connection, bool $wasLogging): int
    {
        if (! $wasLogging) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }

        return count($connection->getQueryLog());
    }

    /** @return array{count: int, time_ms: float} */
    private function finishQueryMeasurement(Connection $connection, bool $wasLogging, int $offset): array
    {
        $queries = array_slice($connection->getQueryLog(), $offset);
        $metrics = [
            'count' => count($queries),
            'time_ms' => array_sum(array_column($queries, 'time')),
        ];

        if (! $wasLogging) {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return $metrics;
    }
}
