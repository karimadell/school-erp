<?php

namespace App\Services\MasterData;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\MasterStudentImport;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentListenerPlacement;
use App\Models\User;
use App\Services\AcademicStructureService;
use App\Services\Transport\TransportAssignmentService;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterStudentImportService
{
    public const YEAR_ID = 1;

    public const DATE = '2026-09-01';

    public function __construct(private MasterDataWorkbookParser $parser, private AcademicStructureService $structure, private TransportAssignmentService $transportAssignments) {}

    public function defaultPath(): string
    {
        return storage_path('app/master-data/СПИСОК УЧЕНИКОВ ОЦ 2026-2027.xlsx');
    }

    public function preview(?string $path = null): array
    {
        return $this->result('PREVIEW', $this->plan($path ?? $this->defaultPath()));
    }

    public function apply(User $actor, ?string $path = null): array
    {
        abort_unless($actor->isActive() && $actor->can('manage students') && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        $plan = $this->preparePlan($path);

        return DB::transaction(fn () => $this->persistPlan($actor, $plan));
    }

    public function preparePlan(?string $path = null): Collection
    {
        return $this->plan($path ?? $this->defaultPath());
    }

    /** Persist a previously validated plan. The caller owns the transaction. */
    public function persistPlan(User $actor, Collection $plan): array
    {
        abort_unless($actor->isActive() && $actor->can('manage students') && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        if ($plan->where('action', 'REVIEW_REQUIRED')->isNotEmpty()) {
            throw ValidationException::withMessages(['identity' => 'Master student apply is blocked by REVIEW_REQUIRED identities.']);
        }
        $created = $enrolled = $listeners = $corrected = $renamed = $merged = $transportEnded = 0;
        $metadata = MasterStudentImport::query()->whereIn('source_key', $plan->pluck('source_key'))->lockForUpdate()->get()->keyBy('source_key');
        $locators = MasterStudentImport::query()->whereIn('source_file', $plan->pluck('source_file')->unique())->lockForUpdate()->get()
            ->keyBy(fn ($row) => $row->source_file."\0".$row->source_sheet."\0".$row->source_row);
        foreach ($plan as $item) {
            $locator = $locators->get($item['source_file']."\0".$item['source_sheet']."\0".$item['source_row']);
            if ($locator && $locator->source_key !== $item['source_key']) {
                throw ValidationException::withMessages(['source' => 'Master student source row changed after import.']);
            }
            $meta = $metadata->get($item['source_key']);
            if ($meta) {
                $this->assertMetadata($meta, $item);

                continue;
            }
            $studentId = $item['student_id'];
            $enrollmentId = $item['enrollment_id'];
            $listenerPlacementId = null;
            if (in_array($item['action'], ['CREATE_ENROLLED', 'CREATE_LISTENER'], true)) {
                $student = Student::create([
                    'name' => $item['canonical_name'],
                    'preferred_name' => $item['preferred_name'],
                    'status' => Student::STATUS_ACTIVE,
                ]);
                $studentId = $student->id;
                $this->audit($actor, 'master_student_created', $student);
                $created++;
                if ($item['action'] === 'CREATE_LISTENER') {
                    $listener = StudentListenerPlacement::create(['student_id' => $student->id, 'academic_year_id' => self::YEAR_ID, 'stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id'], 'source_marker' => 'БЗ', 'status' => 'active', 'effective_from' => self::DATE]);
                    $listenerPlacementId = $listener->id;
                    $this->auditModel($actor, 'master_student_listener_created', $listener);
                    $listeners++;
                } else {
                    $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => self::YEAR_ID, 'enrollment_mode_id' => 1,
                        'study_attendance_mode' => $item['attendance_marker'], 'stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id'],
                        'academic_year' => AcademicYear::findOrFail(self::YEAR_ID)->name, 'enrollment_date' => self::DATE, 'enrolled_at' => self::DATE, 'status' => 'active', 'is_active' => true]);
                    $enrollmentId = $enrollment->id;
                    $this->audit($actor, 'master_enrollment_created', $enrollment);
                    $enrolled++;
                }
            } elseif (in_array($item['action'], ['UPDATE_ENROLLMENT', 'BLINOV_CORRECTION'], true)) {
                $enrollment = Enrollment::lockForUpdate()->findOrFail($enrollmentId);
                if ((int) $enrollment->student_id !== (int) $studentId || (int) $enrollment->academic_year_id !== self::YEAR_ID || ! $enrollment->is_active) {
                    throw ValidationException::withMessages(['academic' => 'Planned academic update no longer targets the same active AY1 enrollment.']);
                }
                $old = $enrollment->toArray();
                $enrollment->update(['stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id']]);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_placement_corrected', 'model' => Enrollment::class, 'model_id' => $enrollment->id, 'old_values' => $old, 'new_values' => $enrollment->fresh()->toArray()]);
                $corrected++;
            } elseif ($item['action'] === 'ENROLL_EXISTING') {
                $student = Student::lockForUpdate()->findOrFail($studentId);
                $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => self::YEAR_ID, 'enrollment_mode_id' => 1,
                    'study_attendance_mode' => $item['attendance_marker'], 'stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id'],
                    'academic_year' => AcademicYear::findOrFail(self::YEAR_ID)->name, 'enrollment_date' => self::DATE, 'enrolled_at' => self::DATE, 'status' => 'active', 'is_active' => true]);
                $enrollmentId = $enrollment->id;
                $this->audit($actor, 'master_enrollment_created', $enrollment);
                $enrolled++;
            } elseif ($item['action'] === 'PLACE_EXISTING_LISTENER') {
                $student = Student::lockForUpdate()->findOrFail($studentId);
                $listener = StudentListenerPlacement::create(['student_id' => $student->id, 'academic_year_id' => self::YEAR_ID, 'stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id'], 'source_marker' => 'БЗ', 'status' => 'active', 'effective_from' => self::DATE]);
                $listenerPlacementId = $listener->id;
                $this->auditModel($actor, 'master_student_listener_created', $listener);
                $listeners++;
            } elseif ($item['action'] === 'ELSHEIKH_RESOLUTION') {
                $student = Student::lockForUpdate()->findOrFail($studentId);
                $old = $student->toArray();
                $student->update(['name' => 'Эльшейх Сухайб', 'preferred_name' => 'Адам']);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_identity_confirmed', 'model' => Student::class, 'model_id' => $student->id, 'old_values' => $old, 'new_values' => $student->fresh()->toArray()]);
                $renamed++;
            } elseif ($item['action'] === 'DENISENKO_RESOLUTION') {
                $duplicate = Student::lockForUpdate()->findOrFail($item['duplicate_student_id']);
                $duplicateEnrollment = Enrollment::lockForUpdate()->findOrFail($item['duplicate_enrollment_id']);
                $oldStudent = $duplicate->toArray();
                $oldEnrollment = $duplicateEnrollment->toArray();
                $assignment = \App\Models\StudentTransportAssignment::findOrFail($item['duplicate_assignment_id']);
                $this->transportAssignments->end($assignment, self::DATE, $actor, 'Confirmed duplicate identity; canonical Transport route is Эль Ахья');
                $duplicateEnrollment->update(['is_active' => false, 'status' => 'withdrawn']);
                $duplicate->update(['merged_into_student_id' => $studentId, 'status' => 'suspended']);
                $this->auditChange($actor, 'master_duplicate_enrollment_merged', $duplicateEnrollment, $oldEnrollment);
                $this->auditChange($actor, 'master_student_identity_merged', $duplicate, $oldStudent);
                $merged++;
                $transportEnded++;
            }
            if ($studentId && $item['name_change'] && ! in_array($item['action'], ['ELSHEIKH_RESOLUTION'], true)) {
                $student = Student::lockForUpdate()->findOrFail($studentId);
                $old = $student->toArray();
                $student->update(['name' => $item['raw_name']]);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_name_updated', 'model' => Student::class, 'model_id' => $student->id, 'old_values' => $old, 'new_values' => $student->fresh()->toArray()]);
                $renamed++;
            }
            if ($studentId && $item['preferred_name'] !== null) {
                $student = Student::lockForUpdate()->findOrFail($studentId);
                if ($student->preferred_name !== $item['preferred_name']) {
                    $old = $student->toArray();
                    $student->update(['preferred_name' => $item['preferred_name']]);
                    AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_preferred_name_confirmed', 'model' => Student::class, 'model_id' => $student->id, 'old_values' => $old, 'new_values' => $student->fresh()->toArray()]);
                    $renamed++;
                }
            }
            MasterStudentImport::create(['source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'], 'source_row' => $item['source_row'],
                'raw_name' => $item['raw_name'], 'raw_class_group' => $item['raw_class_group'], 'attendance_marker' => $item['attendance_marker'], 'resolution_status' => $item['resolution_status'],
                'resolution_evidence' => $item['evidence'], 'source_data' => $item['source_data'], 'student_id' => $studentId, 'enrollment_id' => $enrollmentId, 'listener_placement_id' => $listenerPlacementId]);
        }

        return $this->result('APPLY', $plan) + ['created_students' => $created, 'created_enrollments' => $enrolled, 'created_listeners' => $listeners, 'corrected_enrollments' => $corrected, 'updated_names' => $renamed, 'merged_students' => $merged, 'ended_transport_assignments' => $transportEnded];
    }

    private function plan(string $path): Collection
    {
        $rows = collect($this->parser->students($path));
        if ($rows->count() !== 134 || $rows->pluck(fn ($r) => $this->parser->sourceKey($r, true))->unique()->count() !== 134) {
            throw ValidationException::withMessages(['source' => 'Expected 134 unique master student rows.']);
        }
        $year = AcademicYear::find(self::YEAR_ID);
        $mode = EnrollmentMode::find(1);
        if (! $year || ! $year->is_active || ! $mode || ! $mode->is_active) {
            throw ValidationException::withMessages(['academic' => 'AY1/full-time unavailable.']);
        }
        $students = Student::query()->whereNull('merged_into_student_id')->with([
            'enrollments' => fn ($query) => $query->where('academic_year_id', self::YEAR_ID)->where('is_active', true)->with(['grade', 'transportAssignments.route']),
            'listenerPlacements' => fn ($query) => $query->where('academic_year_id', self::YEAR_ID)->where('status', 'active'),
        ])->get();
        $byName = $students->groupBy(fn ($student) => $this->key($student->russianFullName() ?: $student->name));

        $plan = $rows->map(function ($row) use ($byName) {
            [$stage,$grade,$class] = $this->placement($row['numeric_grade']);
            $matches = $byName->get($this->key($row['raw_name']), collect());
            if ($row['raw_name'] === 'Эльшейх Сухайб') {
                $matches = $matches->concat($byName->get($this->key('Эльшейх Адам'), collect()))->unique('id')->values();
            }
            $action = $row['attendance_marker'] === 'БЗ' ? 'CREATE_LISTENER' : 'CREATE_ENROLLED';
            $status = 'NEW';
            $evidence = 'No existing canonical Student with the normalized authoritative name';
            $studentId = $enrollmentId = null;
            $special = [];
            if ($matches->count() === 1) {
                $match = $matches->first();
                $studentId = $match->id;
                $activeEnrollment = $match->enrollments->first();
                $enrollmentId = $activeEnrollment?->id;
                if ($match->enrollments->count() > 1 || $match->listenerPlacements->count() > 1 || ($row['attendance_marker'] === 'БЗ' && $activeEnrollment)) {
                    $action = 'REVIEW_REQUIRED';
                    $status = 'ACADEMIC_CONFLICT';
                    $evidence = 'Existing Student has conflicting AY1 formal/listener placement records';
                    $special['candidate_enrollment_ids'] = $match->enrollments->pluck('id')->sort()->values()->all();
                } elseif ($row['attendance_marker'] === 'БЗ') {
                    $action = $match->listenerPlacements->isNotEmpty() ? 'LINK' : 'PLACE_EXISTING_LISTENER';
                } elseif (! $activeEnrollment) {
                    $action = 'ENROLL_EXISTING';
                } elseif ((int) $activeEnrollment->grade_id !== $grade || (int) $activeEnrollment->class_id !== $class || $activeEnrollment->study_attendance_mode !== $row['attendance_marker']) {
                    $action = $row['raw_name'] === 'Блинов Добрыня' ? 'BLINOV_CORRECTION' : 'UPDATE_ENROLLMENT';
                } else {
                    $action = 'LINK';
                }
                if ($row['raw_name'] === 'Эльшейх Сухайб' && $this->key($match->name) === $this->key('Эльшейх Адам')) {
                    $action = 'ELSHEIKH_RESOLUTION';
                }
                $status = 'DETERMINISTIC_MATCH';
                $evidence = $row['raw_name'] === 'Эльшейх Сухайб'
                    ? 'Approved legal/preferred-name identity: Эльшейх Сухайб / Адам'
                    : 'Unique normalized authoritative full-name match against existing Student';
            } elseif ($matches->count() > 1) {
                $denis = $row['raw_name'] === 'Денисенко Александра' ? $this->denisenkoResolution($matches) : null;
                if ($denis) {
                    $studentId = $denis['canonical']->id;
                    $enrollmentId = $denis['canonical_enrollment']->id;
                    $action = 'DENISENKO_RESOLUTION';
                    $status = 'DETERMINISTIC_MATCH';
                    $evidence = 'Approved one-child resolution; current Эль Ахья identity retained and Бритиш assignment ended';
                    $special = ['duplicate_student_id' => $denis['duplicate']->id, 'duplicate_enrollment_id' => $denis['duplicate_enrollment']->id,
                        'duplicate_assignment_id' => $denis['duplicate_assignment']->id, 'canonical_assignment_id' => $denis['canonical_assignment']->id];
                } else {
                    $action = 'REVIEW_REQUIRED';
                    $status = 'CONTROLLED_DUPLICATE_CANDIDATE';
                    $evidence = 'Multiple existing Students have the same normalized authoritative name; no automatic merge is permitted';
                    $special['candidate_student_ids'] = $matches->pluck('id')->sort()->values()->all();
                }
            }
            $sourceKey = $this->parser->sourceKey($row, true);
            $locator = MasterStudentImport::where('source_file', $row['source_file'])->where('source_sheet', $row['source_sheet'])->where('source_row', $row['source_row'])->first();
            if ($locator && $locator->source_key !== $sourceKey) {
                throw ValidationException::withMessages(['source' => "Master student source row {$row['source_sheet']}:{$row['source_row']} changed after import."]);
            }
            $existing = MasterStudentImport::where('source_key', $sourceKey)->first();
            $finance = $matches->mapWithKeys(fn ($student) => [$student->id => $this->financeEvidence($student)])->all();
            $nameChange = $studentId ? trim((string) $matches->firstWhere('id', $studentId)?->name) !== $row['raw_name'] : false;

            $preferredName = $row['raw_name'] === 'Эльшейх Сухайб' ? 'Адам' : null;
            $item = $row + ['source_key' => $sourceKey, 'planning_key' => 'master-student:'.$sourceKey,
                'canonical_name' => $row['raw_name'], 'preferred_name' => $preferredName,
                'source_aliases' => $preferredName ? [$preferredName, 'Эльшейх Адам'] : [],
                'stage_id' => $stage, 'grade_id' => $grade, 'class_id' => $class, 'action' => $action, 'resolution_status' => $status, 'evidence' => $evidence,
                'student_id' => $studentId, 'enrollment_id' => $enrollmentId, 'name_change' => $nameChange, 'already_imported' => (bool) $existing, 'source_data' => $row,
                'finance_linked' => collect($finance)->contains(fn ($counts) => array_sum($counts) > 0), 'finance_evidence' => $finance];
            $item += $special;
            if ($existing) {
                $item['resolution_status'] = $existing->resolution_status;
                $item['evidence'] = $existing->resolution_evidence;
                $this->assertMetadata($existing, $item);
            }

            return $item;
        });

        if ($plan->where('attendance_marker', 'ДО')->count() !== 12 || $plan->where('attendance_marker', 'БЗ')->count() !== 4
            || (int) $plan->firstWhere('raw_name', 'Блинов Добрыня')['numeric_grade'] !== 8) {
            throw ValidationException::withMessages(['decisions' => 'Master student proposal violates confirmed attendance or placement semantics.']);
        }

        return $plan;
    }

    private function placement(int $level): array
    {
        $classes = SchoolClass::query()->where('is_active', true)
            ->whereHas('grade', fn ($query) => $query->where('level', $level))->with('grade')->get();
        if ($classes->count() !== 1) {
            throw ValidationException::withMessages(['class' => "Active class for grade {$level} is not unique."]);
        }
        $grade = $classes->first()->grade;
        $this->structure->validatePlacement($grade->stage_id, $grade->id, $classes->first()->id, requireActive: true);

        return [$grade->stage_id, $grade->id, $classes->first()->id];
    }

    private function result(string $mode, Collection $p): array
    {
        return ['mode' => $mode, 'source_rows' => 134, 'safe_existing_matches' => $p->where('resolution_status', 'DETERMINISTIC_MATCH')->count(),
            'new_students' => $p->whereIn('action', ['CREATE_ENROLLED', 'CREATE_LISTENER'])->where('already_imported', false)->count(),
            'formal_standard' => $p->where('attendance_marker', null)->count(), 'formal_home_study' => $p->where('attendance_marker', 'ДО')->count(), 'listeners' => $p->where('attendance_marker', 'БЗ')->count(),
            'new_formal_enrollments' => $p->whereIn('action', ['CREATE_ENROLLED', 'ENROLL_EXISTING'])->count(), 'review_required' => $p->where('action', 'REVIEW_REQUIRED')->values()->all(), 'expected_canonical_real_students' => 134,
            'expected_formal_ay1_enrollments' => 130, 'expected_current_transport_assignments' => 63,
            'elsheikh_resolution' => $p->firstWhere('action', 'ELSHEIKH_RESOLUTION'), 'denisenko_resolution' => $p->firstWhere('action', 'DENISENKO_RESOLUTION'),
            'attendance_markers' => $p->whereNotNull('attendance_marker')->countBy('attendance_marker')->all(), 'blinov_correction' => $p->firstWhere('raw_name', 'Блинов Добрыня'),
            'name_updates' => $p->where('name_change', true)->pluck('raw_name')->values()->all(), 'rows' => $p->values()->all()];
    }

    private function financeEvidence(Student $student): array
    {
        $enrollmentIds = Enrollment::query()->where('student_id', $student->id)->pluck('id');

        return [
            'invoices' => DB::table('invoices')->where('student_id', $student->id)->count(),
            'invoice_items' => DB::table('invoice_items')->whereIn('invoice_id', DB::table('invoices')->where('student_id', $student->id)->select('id'))->count(),
            'invoice_payments' => DB::table('invoice_payments')->whereIn('invoice_id', DB::table('invoices')->where('student_id', $student->id)->select('id'))->count(),
            'service_subscriptions' => DB::table('student_service_subscriptions')->whereIn('enrollment_id', $enrollmentIds)->count(),
        ];
    }

    private function denisenkoResolution(Collection $matches): ?array
    {
        $withRoute = $matches->map(function (Student $student) {
            $enrollment = $student->enrollments->first();
            $assignments = $enrollment?->transportAssignments?->where('status', 'active')->whereNull('effective_to') ?? collect();

            return ['student' => $student, 'enrollment' => $enrollment, 'assignments' => $assignments];
        });
        $canonical = $withRoute->first(fn ($item) => $item['assignments']->contains(fn ($assignment) => $this->key($assignment->route?->name ?? '') === $this->key('Эль Ахья')));
        $duplicate = $withRoute->first(fn ($item) => $item['assignments']->contains(fn ($assignment) => $this->key($assignment->route?->name ?? '') === $this->key('Бритиш')));
        if (! $canonical || ! $duplicate || $canonical['student']->is($duplicate['student'])
            || $canonical['assignments']->count() !== 1 || $duplicate['assignments']->count() !== 1
            || array_sum($this->financeEvidence($duplicate['student'])) > 0) {
            return null;
        }

        return ['canonical' => $canonical['student'], 'canonical_enrollment' => $canonical['enrollment'],
            'canonical_assignment' => $canonical['assignments']->first(fn ($assignment) => $this->key($assignment->route?->name ?? '') === $this->key('Эль Ахья')),
            'duplicate' => $duplicate['student'], 'duplicate_enrollment' => $duplicate['enrollment'],
            'duplicate_assignment' => $duplicate['assignments']->first(fn ($assignment) => $this->key($assignment->route?->name ?? '') === $this->key('Бритиш'))];
    }

    private function key(string $v): string
    {
        return mb_strtolower(str_replace('ё', 'е', trim(preg_replace('/\s+/u', ' ', $v))));
    }

    private function assertMetadata(MasterStudentImport $m, array $i): void
    {
        $identityMatches = false;
        if ($i['action'] === 'REVIEW_REQUIRED') {
            $identityMatches = $m->student_id === null && $m->enrollment_id === null;
        } elseif ($i['student_id']) {
            $identityMatches = (int) $m->student_id === (int) $i['student_id'] && (int) $m->enrollment_id === (int) $i['enrollment_id'];
            if ($i['action'] === 'ELSHEIKH_RESOLUTION') {
                $identityMatches = $identityMatches && $m->student?->name === 'Эльшейх Сухайб' && $m->student?->preferred_name === 'Адам';
            } elseif ($i['action'] === 'DENISENKO_RESOLUTION') {
                $duplicate = Student::find($i['duplicate_student_id']);
                $assignment = \App\Models\StudentTransportAssignment::find($i['duplicate_assignment_id']);
                $identityMatches = $identityMatches && (int) $duplicate?->merged_into_student_id === (int) $m->student_id
                    && ! Enrollment::find($i['duplicate_enrollment_id'])?->is_active && $assignment?->status === 'ended' && $assignment?->effective_to !== null;
            }
        } elseif ($i['action'] === 'CREATE_LISTENER' && $m->student && $m->listenerPlacement) {
            $identityMatches = (int) $m->listenerPlacement->student_id === (int) $m->student_id && $m->enrollment_id === null
                && (int) $m->listenerPlacement->academic_year_id === self::YEAR_ID && $m->listenerPlacement->status === 'active'
                && (int) $m->listenerPlacement->stage_id === (int) $i['stage_id'] && (int) $m->listenerPlacement->grade_id === (int) $i['grade_id']
                && (int) $m->listenerPlacement->class_id === (int) $i['class_id'] && $m->listenerPlacement->source_marker === 'БЗ'
                && $m->student->name === $i['canonical_name'] && $m->student->preferred_name === $i['preferred_name'];
        } elseif ($m->student && $m->enrollment) {
            $identityMatches = (int) $m->enrollment->student_id === (int) $m->student_id
                && (int) $m->enrollment->academic_year_id === self::YEAR_ID && $m->enrollment->is_active
                && (int) $m->enrollment->stage_id === (int) $i['stage_id'] && (int) $m->enrollment->grade_id === (int) $i['grade_id']
                && (int) $m->enrollment->class_id === (int) $i['class_id'] && $m->enrollment->study_attendance_mode === $i['attendance_marker']
                && $m->student->name === $i['canonical_name'] && $m->student->preferred_name === $i['preferred_name'];
        }
        if ($i['raw_name'] === 'Эльшейх Сухайб') {
            $identityMatches = $identityMatches && $m->student?->name === 'Эльшейх Сухайб' && $m->student?->preferred_name === 'Адам';
        }
        if ($m->source_file !== $i['source_file'] || $m->source_sheet !== $i['source_sheet'] || (int) $m->source_row !== $i['source_row'] || $m->raw_name !== $i['raw_name']
            || $m->raw_class_group !== $i['raw_class_group'] || $m->attendance_marker !== $i['attendance_marker'] || $m->resolution_status !== $i['resolution_status']
            || $m->resolution_evidence !== $i['evidence'] || $m->source_data != $i['source_data'] || ! $identityMatches) {
            throw ValidationException::withMessages(['source' => 'Master student metadata conflict.']);
        }
    }

    private function audit(User $actor, string $action, Student|Enrollment $model): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'model' => $model::class, 'model_id' => $model->id, 'old_values' => null, 'new_values' => $model->toArray()]);
    }

    private function auditModel(User $actor, string $action, \Illuminate\Database\Eloquent\Model $model): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'model' => $model::class, 'model_id' => $model->id, 'old_values' => null, 'new_values' => $model->toArray()]);
    }

    private function auditChange(User $actor, string $action, \Illuminate\Database\Eloquent\Model $model, array $old): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'model' => $model::class, 'model_id' => $model->id, 'old_values' => $old, 'new_values' => $model->fresh()->toArray()]);
    }
}
