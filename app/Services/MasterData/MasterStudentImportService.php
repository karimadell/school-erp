<?php

namespace App\Services\MasterData;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\MasterStudentImport;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentBootstrapImport;
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

        return DB::transaction(function () use ($actor, $path) {
            $plan = $this->plan($path ?? $this->defaultPath());
            $created = $enrolled = $listeners = $corrected = $renamed = $merged = $transportEnded = 0;
            foreach ($plan as $item) {
                $locator = MasterStudentImport::where('source_file', $item['source_file'])
                    ->where('source_sheet', $item['source_sheet'])->where('source_row', $item['source_row'])->lockForUpdate()->first();
                if ($locator && $locator->source_key !== $item['source_key']) {
                    throw ValidationException::withMessages(['source' => 'Master student source row changed after import.']);
                }
                $meta = MasterStudentImport::where('source_key', $item['source_key'])->lockForUpdate()->first();
                if ($meta) {
                    $this->assertMetadata($meta, $item);

                    continue;
                }
                $studentId = $item['student_id'];
                $enrollmentId = $item['enrollment_id'];
                $listenerPlacementId = null;
                if (in_array($item['action'], ['CREATE_ENROLLED', 'CREATE_LISTENER'], true)) {
                    $student = Student::create(['name' => $item['raw_name'], 'status' => Student::STATUS_ACTIVE]);
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
                } elseif ($item['action'] === 'BLINOV_CORRECTION') {
                    $enrollment = Enrollment::lockForUpdate()->findOrFail($enrollmentId);
                    if ((int) $enrollment->student_id !== (int) $studentId || (int) $enrollment->academic_year_id !== self::YEAR_ID || ! $enrollment->is_active
                        || (int) $enrollment->grade?->level !== 7) {
                        throw ValidationException::withMessages(['blinov' => 'Approved Блинов correction requires the active AY1 enrollment to remain in grade 7.']);
                    }
                    $old = $enrollment->toArray();
                    $enrollment->update(['stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id']]);
                    AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_placement_corrected', 'model' => Enrollment::class, 'model_id' => $enrollment->id, 'old_values' => $old, 'new_values' => $enrollment->fresh()->toArray()]);
                    $corrected++;
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
                MasterStudentImport::create(['source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'], 'source_row' => $item['source_row'],
                    'raw_name' => $item['raw_name'], 'raw_class_group' => $item['raw_class_group'], 'attendance_marker' => $item['attendance_marker'], 'resolution_status' => $item['resolution_status'],
                    'resolution_evidence' => $item['evidence'], 'source_data' => $item['source_data'], 'student_id' => $studentId, 'enrollment_id' => $enrollmentId, 'listener_placement_id' => $listenerPlacementId]);
            }

            return $this->result('APPLY', $plan) + ['created_students' => $created, 'created_enrollments' => $enrolled, 'created_listeners' => $listeners, 'corrected_enrollments' => $corrected, 'updated_names' => $renamed, 'merged_students' => $merged, 'ended_transport_assignments' => $transportEnded];
        });
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
        $allImports = StudentBootstrapImport::with(['student', 'enrollment'])->get();
        if ($allImports->count() !== 64 || $allImports->unique('student_id')->count() !== 64 || $allImports->unique('enrollment_id')->count() !== 64
            || $allImports->contains(fn ($i) => ! $i->student || ! $i->enrollment || (int) $i->enrollment->student_id !== (int) $i->student_id
                || (int) $i->enrollment->academic_year_id !== self::YEAR_ID
                || (! $i->enrollment->is_active && ! ($i->raw_full_name === 'Денисенко Александра' && str_contains($i->source_file, 'Бритиш') && $i->student->merged_into_student_id)))) {
            throw ValidationException::withMessages(['baseline' => 'Expected exactly 64 valid canonical Transport bootstrap identities for active AY1 enrollments.']);
        }
        $this->assertSensitiveBootstrapEvidence($allImports);
        $imports = $allImports->groupBy(fn ($i) => $this->key($i->raw_full_name));

        $plan = $rows->map(function ($row) use ($imports, $allImports) {
            [$stage,$grade,$class] = $this->placement($row['numeric_grade']);
            $matches = $imports->get($this->key($row['raw_name']), collect());
            $action = $row['attendance_marker'] === 'БЗ' ? 'CREATE_LISTENER' : 'CREATE_ENROLLED';
            $status = 'NEW';
            $evidence = 'No Transport bootstrap identity with exact authoritative name';
            $studentId = $enrollmentId = null;
            $special = [];
            if ($row['raw_name'] === 'Эльшейх Сухайб') {
                $match = $allImports->firstWhere('raw_full_name', 'Эльшейх Адам');
                $studentId = $match->student_id;
                $enrollmentId = $match->enrollment_id;
                $action = 'ELSHEIKH_RESOLUTION';
                $status = 'CONFIRMED_MATCH';
                $evidence = 'Staff-confirmed one child: legal name Эльшейх Сухайб; Адам retained as preferred/source name';
            } elseif ($row['raw_name'] === 'Денисенко Александра') {
                $match = $matches->first(fn ($i) => str_contains($i->source_file, 'Эль Ахья'));
                $duplicate = $matches->first(fn ($i) => str_contains($i->source_file, 'Бритиш'));
                if (! $match || ! $duplicate) {
                    throw ValidationException::withMessages(['identity' => 'Confirmed Денисенко source identities/current assignments are incomplete.']);
                }
                $assignments = \App\Models\StudentTransportAssignment::where('enrollment_id', $duplicate->enrollment_id)->where('transport_route_id', 3)->where('bus_id', 3)
                    ->where(fn ($query) => $query->where(fn ($active) => $active->where('status', 'active')->whereNull('effective_to'))
                        ->orWhere(fn ($ended) => $ended->where('status', 'ended')->whereNotNull('effective_to')->where('change_reason', 'Confirmed duplicate identity; canonical Transport route is Эль Ахья')))->get();
                $canonicalAssignments = \App\Models\StudentTransportAssignment::where('enrollment_id', $match->enrollment_id)->where('transport_route_id', 5)->where('bus_id', 5)->where('status', 'active')->whereNull('effective_to')->get();
                if ($assignments->count() !== 1 || $canonicalAssignments->count() !== 1) {
                    throw ValidationException::withMessages(['identity' => 'Confirmed Денисенко requires current Bus 3/Бритиш and Bus 5/Эль Ахья assignments.']);
                }
                $assignment = $assignments->first();
                $canonicalAssignment = $canonicalAssignments->first();
                $studentId = $match->student_id;
                $enrollmentId = $match->enrollment_id;
                $action = 'DENISENKO_RESOLUTION';
                $status = 'CONFIRMED_MATCH';
                $evidence = 'Staff-confirmed one student; retain Эль Ахья and end Бритиш assignment';
                $special = ['duplicate_student_id' => $duplicate->student_id, 'duplicate_enrollment_id' => $duplicate->enrollment_id, 'duplicate_assignment_id' => $assignment->id, 'canonical_assignment_id' => $canonicalAssignment->id];
            } elseif ($matches->count() === 1) {
                $match = $matches->first();
                $studentId = $match->student_id;
                $enrollmentId = $match->enrollment_id;
                $action = $row['raw_name'] === 'Блинов Добрыня' ? 'BLINOV_CORRECTION' : 'LINK';
                $status = $action === 'LINK' ? 'EXACT_MATCH' : 'STRONG_MATCH';
                $evidence = $action === 'LINK' ? 'Unique exact authoritative name' : 'Approved exact name/contact match; class correction 7 -> 8';
            } elseif ($matches->count() > 1) {
                throw ValidationException::withMessages(['identity' => "Unexpected duplicate exact identity {$row['raw_name']}."]);
            }
            $sourceKey = $this->parser->sourceKey($row, true);
            $locator = MasterStudentImport::where('source_file', $row['source_file'])->where('source_sheet', $row['source_sheet'])->where('source_row', $row['source_row'])->first();
            if ($locator && $locator->source_key !== $sourceKey) {
                throw ValidationException::withMessages(['source' => "Master student source row {$row['source_sheet']}:{$row['source_row']} changed after import."]);
            }
            $existing = MasterStudentImport::where('source_key', $sourceKey)->first();
            $nameChange = $studentId ? trim((string) Student::find($studentId)?->name) !== $row['raw_name'] : false;

            $item = $row + ['source_key' => $sourceKey, 'stage_id' => $stage, 'grade_id' => $grade, 'class_id' => $class, 'action' => $action, 'resolution_status' => $status, 'evidence' => $evidence,
                'student_id' => $studentId, 'enrollment_id' => $enrollmentId, 'name_change' => $nameChange, 'already_imported' => (bool) $existing, 'source_data' => $row];
            $item += $special;
            if ($existing) {
                $this->assertMetadata($existing, $item);
            }

            return $item;
        });

        if ($plan->whereIn('resolution_status', ['EXACT_MATCH', 'STRONG_MATCH', 'CONFIRMED_MATCH'])->count() !== 63
            || $plan->where('action', 'CREATE_ENROLLED')->count() !== 67 || $plan->where('action', 'CREATE_LISTENER')->count() !== 4
            || $plan->where('action', 'REVIEW_REQUIRED')->isNotEmpty()
            || $plan->where('action', 'BLINOV_CORRECTION')->pluck('raw_name')->all() !== ['Блинов Добрыня']
            || $plan->where('action', 'DENISENKO_RESOLUTION')->count() !== 1 || $plan->where('action', 'ELSHEIKH_RESOLUTION')->count() !== 1) {
            throw ValidationException::withMessages(['decisions' => 'Master student proposal does not match the confirmed 63 linked / 67 enrolled / 4 listener decision set.']);
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
        return ['mode' => $mode, 'source_rows' => 134, 'safe_existing_matches' => $p->whereIn('resolution_status', ['EXACT_MATCH', 'STRONG_MATCH', 'CONFIRMED_MATCH'])->count(),
            'new_students' => $p->whereIn('action', ['CREATE_ENROLLED', 'CREATE_LISTENER'])->where('already_imported', false)->count(),
            'formal_standard' => $p->where('attendance_marker', null)->count(), 'formal_home_study' => $p->where('attendance_marker', 'ДО')->count(), 'listeners' => $p->where('action', 'CREATE_LISTENER')->count(),
            'new_formal_enrollments' => $p->where('action', 'CREATE_ENROLLED')->count(), 'review_required' => [], 'expected_canonical_real_students' => 134,
            'expected_formal_ay1_enrollments' => 130, 'expected_current_transport_assignments' => 63,
            'elsheikh_resolution' => $p->firstWhere('action', 'ELSHEIKH_RESOLUTION'), 'denisenko_resolution' => $p->firstWhere('action', 'DENISENKO_RESOLUTION'),
            'attendance_markers' => $p->whereNotNull('attendance_marker')->countBy('attendance_marker')->all(), 'blinov_correction' => $p->firstWhere('raw_name', 'Блинов Добрыня'),
            'name_updates' => $p->where('name_change', true)->pluck('raw_name')->values()->all(), 'rows' => $p->values()->all()];
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
                && (int) $m->listenerPlacement->class_id === (int) $i['class_id'] && $m->listenerPlacement->source_marker === 'БЗ';
        } elseif ($m->student && $m->enrollment) {
            $identityMatches = (int) $m->enrollment->student_id === (int) $m->student_id
                && (int) $m->enrollment->academic_year_id === self::YEAR_ID && $m->enrollment->is_active
                && (int) $m->enrollment->stage_id === (int) $i['stage_id'] && (int) $m->enrollment->grade_id === (int) $i['grade_id']
                && (int) $m->enrollment->class_id === (int) $i['class_id'] && $m->enrollment->study_attendance_mode === $i['attendance_marker'];
        }
        if ($m->source_file !== $i['source_file'] || $m->source_sheet !== $i['source_sheet'] || (int) $m->source_row !== $i['source_row'] || $m->raw_name !== $i['raw_name']
            || $m->raw_class_group !== $i['raw_class_group'] || $m->attendance_marker !== $i['attendance_marker'] || $m->resolution_status !== $i['resolution_status']
            || $m->resolution_evidence !== $i['evidence'] || $m->source_data != $i['source_data'] || ! $identityMatches) {
            throw ValidationException::withMessages(['source' => 'Master student metadata conflict.']);
        }
    }

    private function assertSensitiveBootstrapEvidence(Collection $imports): void
    {
        $exact = fn (string $name, string $route, int $row) => $imports->filter(fn ($i) => $i->raw_full_name === $name && str_contains($i->source_file, $route) && (int) $i->source_row === $row);
        $britishDenis = $exact('Денисенко Александра', 'Бритиш', 14);
        $ahyaDenis = $exact('Денисенко Александра', 'Эль Ахья', 5);
        $blinov = $exact('Блинов Добрыня', 'Эль Ахья', 8);
        $adam = $exact('Эльшейх Адам', 'Каусер', 17);
        if ($imports->where('raw_full_name', 'Денисенко Александра')->count() !== 2 || $britishDenis->count() !== 1 || $ahyaDenis->count() !== 1
            || $britishDenis->first()->student_id === $ahyaDenis->first()->student_id || (int) $britishDenis->first()->raw_class !== 10 || blank($britishDenis->first()->raw_contact)
            || $blinov->count() !== 1 || (int) $blinov->first()->raw_class !== 7 || blank($blinov->first()->raw_contact) || $adam->count() !== 1) {
            throw ValidationException::withMessages(['identity' => 'Sensitive Денисенко / Блинов / Эльшейх Transport source evidence has drifted.']);
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
