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
use App\Models\User;
use App\Services\AcademicStructureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterStudentImportService
{
    public const YEAR_ID = 1;

    public const DATE = '2026-09-01';

    public function __construct(private MasterDataWorkbookParser $parser, private AcademicStructureService $structure) {}

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
        abort_unless($actor->isActive() && $actor->can('manage students'), 403);

        return DB::transaction(function () use ($actor, $path) {
            $plan = $this->plan($path ?? $this->defaultPath());
            $created = $corrected = $renamed = 0;
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
                if ($item['action'] === 'CREATE') {
                    $student = Student::create(['name' => $item['raw_name'], 'status' => Student::STATUS_ACTIVE]);
                    $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => self::YEAR_ID, 'enrollment_mode_id' => 1,
                        'study_attendance_mode' => $item['attendance_marker'], 'stage_id' => $item['stage_id'], 'grade_id' => $item['grade_id'], 'class_id' => $item['class_id'],
                        'academic_year' => AcademicYear::findOrFail(self::YEAR_ID)->name, 'enrollment_date' => self::DATE, 'enrolled_at' => self::DATE, 'status' => 'active', 'is_active' => true]);
                    $studentId = $student->id;
                    $enrollmentId = $enrollment->id;
                    $this->audit($actor, 'master_student_created', $student);
                    $this->audit($actor, 'master_enrollment_created', $enrollment);
                    $created++;
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
                }
                if ($studentId && $item['name_change']) {
                    $student = Student::lockForUpdate()->findOrFail($studentId);
                    $old = $student->toArray();
                    $student->update(['name' => $item['raw_name']]);
                    AuditLog::create(['user_id' => $actor->id, 'action' => 'master_student_name_updated', 'model' => Student::class, 'model_id' => $student->id, 'old_values' => $old, 'new_values' => $student->fresh()->toArray()]);
                    $renamed++;
                }
                MasterStudentImport::create(['source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'], 'source_row' => $item['source_row'],
                    'raw_name' => $item['raw_name'], 'raw_class_group' => $item['raw_class_group'], 'attendance_marker' => $item['attendance_marker'], 'resolution_status' => $item['resolution_status'],
                    'resolution_evidence' => $item['evidence'], 'source_data' => $item['source_data'], 'student_id' => $studentId, 'enrollment_id' => $enrollmentId]);
            }

            return $this->result('APPLY', $plan) + ['created_students' => $created, 'created_enrollments' => $created, 'corrected_enrollments' => $corrected, 'updated_names' => $renamed];
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
                || (int) $i->enrollment->academic_year_id !== self::YEAR_ID || ! $i->enrollment->is_active)) {
            throw ValidationException::withMessages(['baseline' => 'Expected exactly 64 valid canonical Transport bootstrap identities for active AY1 enrollments.']);
        }
        $this->assertSensitiveBootstrapEvidence($allImports);
        $imports = $allImports->groupBy(fn ($i) => $this->key($i->raw_full_name));

        $plan = $rows->map(function ($row) use ($imports) {
            [$stage,$grade,$class] = $this->placement($row['numeric_grade']);
            $matches = $imports->get($this->key($row['raw_name']), collect());
            $action = 'CREATE';
            $status = 'NEW';
            $evidence = 'No Transport bootstrap identity with exact authoritative name';
            $studentId = $enrollmentId = null;
            if ($row['raw_name'] === 'Эльшейх Сухайб') {
                $action = 'REVIEW_REQUIRED';
                $status = 'REVIEW_REQUIRED';
                $evidence = 'Possible identity conflict with existing Transport student Эльшейх Адам';
            } elseif ($row['raw_name'] === 'Денисенко Александра') {
                $match = $matches->first(fn ($i) => str_contains($i->source_file, 'Бритиш'));
                if (! $match) {
                    throw ValidationException::withMessages(['identity' => 'Approved Бритиш Денисенко identity missing.']);
                } $studentId = $match->student_id;
                $enrollmentId = $match->enrollment_id;
                $action = 'LINK';
                $status = 'STRONG_MATCH';
                $evidence = 'Approved Бритиш source row; exact name, class, and phone';
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
            if ($existing) {
                $this->assertMetadata($existing, $item);
            }

            return $item;
        });

        if ($plan->whereIn('resolution_status', ['EXACT_MATCH', 'STRONG_MATCH'])->count() !== 62
            || $plan->where('action', 'CREATE')->count() !== 71
            || $plan->where('action', 'REVIEW_REQUIRED')->pluck('raw_name')->all() !== ['Эльшейх Сухайб']
            || $plan->where('action', 'BLINOV_CORRECTION')->pluck('raw_name')->all() !== ['Блинов Добрыня']
            || ! str_contains((string) $plan->firstWhere('raw_name', 'Денисенко Александра')['evidence'], 'Бритиш')) {
            throw ValidationException::withMessages(['decisions' => 'Master student proposal does not match the approved 62 linked / 71 create / 1 review decision set.']);
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
        $reviewRequired = [
            [
                'name' => 'Денисенко Александра',
                'identity' => 'existing Transport source Эль Ахья row 5',
                'decision' => 'Keep distinct and unchanged; master row is linked only to the approved Бритиш identity.',
            ],
            [
                'name' => 'Эльшейх Адам',
                'identity' => 'existing Transport student',
                'decision' => 'Keep unchanged; do not merge with Эльшейх Сухайб.',
            ],
        ];
        foreach ($p->where('action', 'REVIEW_REQUIRED') as $item) {
            $reviewRequired[] = [
                'name' => $item['raw_name'],
                'identity' => "master source {$item['source_sheet']} row {$item['source_row']}",
                'decision' => $item['evidence'],
            ];
        }

        return ['mode' => $mode, 'source_rows' => 134, 'safe_existing_matches' => $p->whereIn('resolution_status', ['EXACT_MATCH', 'STRONG_MATCH'])->count(), 'new_students' => $p->where('action', 'CREATE')->where('already_imported', false)->count(), 'review_required' => $reviewRequired, 'attendance_markers' => $p->whereNotNull('attendance_marker')->countBy('attendance_marker')->all(), 'blinov_correction' => $p->firstWhere('raw_name', 'Блинов Добрыня'), 'name_updates' => $p->where('name_change', true)->pluck('raw_name')->values()->all(), 'rows' => $p->values()->all()];
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
}
