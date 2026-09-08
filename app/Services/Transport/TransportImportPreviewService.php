<?php

namespace App\Services\Transport;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Read-only matching preview for the school's transport workbooks.
 *
 * This class deliberately has no writes, transactions, or mutating model
 * calls. Its output is a proposal for a later, separately approved import.
 */
class TransportImportPreviewService
{
    private const ROUTE_ZONE_HINTS = [
        'Каусер' => 'Зона 1',
        'Арабия' => 'Зона 2',
        'Эль Ахья' => 'Зона 3',
        'Бритиш' => 'UNCONFIRMED',
        'Бествэй' => 'UNCONFIRMED',
    ];

    public function preview(array $paths, ?AcademicYear $year = null): array
    {
        $year ??= AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->first();
        $rows = collect();

        foreach ($paths as $path) {
            $rows = $rows->concat($this->parseWorkbook($path));
        }

        $enrollments = Enrollment::query()
            ->with(['student', 'schoolClass', 'grade', 'academicYear'])
            ->where('is_active', true)
            ->when($year, fn ($q) => $q->where('academic_year_id', $year->id))
            ->get();
        $users = User::query()->with('teacher')->where('is_active', true)->get();
        $routes = TransportRoute::query()->get()->groupBy(fn ($route) => $this->key($route->name));
        $assignments = StudentTransportAssignment::query()->with('enrollment.student')->where('status', StudentTransportAssignment::STATUS_ACTIVE)->get();
        $staffAssignments = \App\Models\VehicleStaffAssignment::query()->with('user')->whereNull('effective_to')->get();

        $previewRows = $rows->map(function (array $row) use ($enrollments, $users, $routes, $assignments, $staffAssignments) {
            $type = $this->classify($row);
            $routeCandidates = $routes->get($this->key($row['route']), collect());
            $route = $routeCandidates->count() === 1 ? $routeCandidates->first() : null;
            $match = $type === 'STUDENT'
                ? $this->matchStudent($row, $enrollments)
                : ($type === 'STAFF' ? $this->matchStaff($row, $users) : $this->emptyMatch());
            $canonicalStudentId = $match['student_id'] ?? null;
            $studentConflicts = $canonicalStudentId
                ? $assignments->filter(fn ($a) => (int) optional($a->enrollment?->student)->id === (int) $canonicalStudentId)->map(fn ($a) => "active_assignment:{$a->id}")->values()->all()
                : [];
            $staffConflicts = ($match['user_id'] ?? null)
                ? $staffAssignments->where('user_id', $match['user_id'])->map(fn ($a) => "active_staff_assignment:{$a->id}")->values()->all()
                : [];

            return array_merge($row, [
                'type' => $type,
                'match_status' => $match['status'],
                'canonical_id' => $type === 'STUDENT' ? ($match['student_id'] ?? null) : ($match['user_id'] ?? null),
                'canonical_name' => $match['canonical_name'] ?? null,
                'enrollment_id' => $match['enrollment_id'] ?? null,
                'canonical_class' => $match['canonical_class'] ?? null,
                'match_evidence' => $match['evidence'] ?? null,
                'candidates' => $match['candidates'] ?? [],
                'weekdays' => $type === 'STAFF' ? $this->weekdays($row['raw_notes'].' '.$row['raw_full_name']) : [],
                'route_status' => $routeCandidates->count() > 1 ? 'AMBIGUOUS' : ($route ? 'EXACT' : 'NOT_FOUND'),
                'route_id' => $route?->id,
                'route_active' => $route?->is_active,
                'route_candidates' => $routeCandidates->map(fn ($candidate) => ['route_id' => $candidate->id, 'name' => $candidate->name, 'is_active' => (bool) $candidate->is_active])->values()->all(),
                'route_pricing_zone_hint' => self::ROUTE_ZONE_HINTS[$row['route']] ?? 'UNCONFIRMED',
                'vehicle_status' => 'VEHICLE_UNRESOLVED',
                'conflicts' => array_values(array_merge($studentConflicts, $staffConflicts)),
                'notes' => $type === 'UNKNOWN' ? 'Не найдено явное указание ученика или сотрудника (сотр).' : ($match['notes'] ?? null),
            ]);
        });

        return [
            'read_only' => true,
            'academic_year_id' => $year?->id,
            'files' => array_values($paths),
            'rows' => $previewRows->values()->all(),
            'summary' => $this->summary($previewRows),
            'duplicate_student_conflicts' => $this->duplicateStudents($previewRows),
            'duplicate_staff_conflicts' => $this->duplicateStaff($previewRows),
            'data_conflicts' => $this->dataConflicts($previewRows),
            'pickup_point_suggestions' => $this->pickupSuggestions($previewRows),
            'capacity' => $this->capacity($previewRows),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function parseWorkbook(string $path): Collection
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("Source workbook not found: {$path}");
        }

        $workbook = IOFactory::load($path);
        $route = $this->routeFromFilename($path);
        $rows = collect();
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $values = $sheet->toArray(null, true, true, true);
            $headerRow = collect($values)->search(fn ($value) => collect($value)->contains(fn ($cell) => in_array($this->key((string) $cell), ['фио', 'фио ученика', 'номер', '№'], true)));
            $headerRow = $headerRow === false ? 1 : $headerRow;
            $headers = $this->headers($values[$headerRow] ?? []);
            foreach ($values as $rowNumber => $valuesByColumn) {
                // Workbooks may have one or more title/metadata rows before
                // the canonical column header. They are not source records.
                // Preserve the spreadsheet row number for every real record
                // after the header, while ignoring empty trailing rows.
                if ($rowNumber <= $headerRow || collect($valuesByColumn)->filter(fn ($value) => filled($value))->isEmpty()) {
                    continue;
                }
                $get = fn (array $names) => collect($names)->map(fn ($name) => $headers[$this->key($name)] ?? null)->filter(fn ($column) => $column !== null)->map(fn ($column) => $valuesByColumn[$column] ?? null)->first(fn ($value) => filled($value));
                $rawName = (string) ($get(['ФИО', 'ФИО ученика', 'Имя']) ?? '');
                $rawClass = (string) ($get(['Класс', 'Класс ученика']) ?? '');
                $rawPickup = $get(['Остановка', 'Место посадки', 'Остановка посадки']);
                $rawPhone = $get(['Телефон', 'Телефон/контакт', 'Контакт']);
                $rawNotes = $get(['Примечание', 'Примечания', 'Комментарии', 'Notes']) ?? '';
                $rows->push([
                    'source_file' => basename($path), 'source_sheet' => $sheet->getTitle(), 'source_row' => $rowNumber,
                    'route' => $route, 'raw_full_name' => $rawName, 'raw_class' => $rawClass,
                    'raw_pickup_point' => $rawPickup, 'raw_phone' => $rawPhone, 'raw_notes' => (string) $rawNotes,
                    'pickup_point' => $rawPickup, 'phone' => $rawPhone,
                ]);
            }
        }

        return $rows;
    }

    public function classify(array $row): string
    {
        return preg_match('/\bсотр\b/iu', implode(' ', [$row['raw_full_name'] ?? '', $row['raw_class'] ?? '', $row['raw_notes'] ?? ''])) ? 'STAFF' : (filled($row['raw_full_name'] ?? null) ? 'STUDENT' : 'UNKNOWN');
    }

    private function matchStudent(array $row, Collection $enrollments): array
    {
        $name = $this->nameKey($row['raw_full_name']);
        $class = $this->key($row['raw_class']);
        $candidates = $enrollments->filter(fn ($e) => $this->nameKey($e->student?->russianFullName() ?: $e->student?->name) === $name);
        $phone = preg_replace('/\D+/', '', (string) ($row['raw_phone'] ?? ''));
        $phoneCandidates = $phone === '' ? collect() : $enrollments->filter(fn ($e) => preg_replace('/\D+/', '', (string) $e->student?->phone) === $phone);
        if ($candidates->isEmpty() && $phoneCandidates->count() > 0) {
            $candidates = $phoneCandidates;
            $phoneEvidence = true;
        } else {
            $phoneEvidence = false;
        }
        if ($candidates->count() > 1) {
            $classCandidates = $candidates->filter(fn ($e) => $this->classMatches($e, $class));
            if ($classCandidates->count() === 1) {
                $candidates = $classCandidates;
            }
        }
        $candidateData = $candidates->map(fn ($e) => ['student_id' => $e->student_id, 'enrollment_id' => $e->id, 'canonical_name' => $e->student?->russianFullName() ?: $e->student?->name, 'canonical_class' => $e->schoolClass?->name ?: $e->grade?->name])->values()->all();
        if ($candidates->count() === 0) {
            return ['status' => 'NOT_FOUND', 'evidence' => 'normalized full name/phone + active enrollment', 'candidates' => []];
        }
        if ($candidates->count() > 1) {
            return ['status' => 'AMBIGUOUS', 'evidence' => 'same normalized name has multiple active enrollments', 'candidates' => $candidateData];
        }
        $e = $candidates->first();
        $classMatch = $this->classMatches($e, $class);

        return ['status' => $phoneEvidence ? 'PROBABLE' : ($classMatch ? 'EXACT' : 'PROBABLE'), 'student_id' => $e->student_id, 'enrollment_id' => $e->id, 'canonical_name' => $e->student?->russianFullName() ?: $e->student?->name, 'canonical_class' => $e->schoolClass?->name ?: $e->grade?->name, 'evidence' => $phoneEvidence ? 'canonical phone match' : ($classMatch ? 'normalized full name + class' : 'normalized full name; class differs or unavailable'), 'candidates' => $candidateData];
    }

    private function matchStaff(array $row, Collection $users): array
    {
        $name = $this->nameKey(preg_replace('/\(.*?\)/u', '', preg_replace('/\bсотр\b/iu', '', $row['raw_full_name'])));
        $candidates = $users->filter(fn ($user) => $this->nameKey($user->name) === $name || ($user->teacher && $this->nameKey(collect([$user->teacher->last_name, $user->teacher->first_name, $user->teacher->patronymic])->filter()->implode(' ')) === $name));
        $candidateData = $candidates->map(fn ($user) => ['user_id' => $user->id, 'canonical_name' => $user->name])->values()->all();
        if ($candidates->count() > 1) {
            return ['status' => 'AMBIGUOUS', 'evidence' => 'multiple canonical users match staff name', 'candidates' => $candidateData];
        }
        if ($candidates->count() === 0) {
            return ['status' => 'NOT_FOUND', 'evidence' => 'normalized staff name', 'candidates' => []];
        }

        return ['status' => 'EXACT', 'user_id' => $candidates->first()->id, 'canonical_name' => $candidates->first()->name, 'evidence' => 'canonical users.name or teacher.user_id', 'candidates' => $candidateData];
    }

    private function emptyMatch(): array
    {
        return ['status' => 'NOT_FOUND', 'candidates' => []];
    }

    private function headers(array $row): array
    {
        return collect($row)->mapWithKeys(fn ($value, $column) => [$this->key((string) $value) => $column])->all();
    }

    private function routeFromFilename(string $path): string
    {
        $base = pathinfo($path, PATHINFO_FILENAME);

        return trim(preg_replace('/^Трансфер[_ -]*/u', '', $base));
    }

    private function key(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private function nameKey(?string $value): string
    {
        return $this->key(preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $value));
    }

    private function classMatches($enrollment, string $class): bool
    {
        return $class !== '' && in_array($class, [$this->key($enrollment->schoolClass?->name), $this->key($enrollment->grade?->name), $this->key((string) $enrollment->grade?->level)], true);
    }

    private function weekdays(string $value): array
    {
        $days = ['пн' => 'monday', 'вт' => 'tuesday', 'ср' => 'wednesday', 'чт' => 'thursday', 'пт' => 'friday', 'сб' => 'saturday', 'вс' => 'sunday'];
        preg_match('/\(([^)]*)\)/u', $value, $m);

        return collect(preg_split('/\s*,\s*/u', $m[1] ?? ''))->map(fn ($d) => $days[$this->key($d)] ?? null)->filter()->values()->all();
    }

    private function summary(Collection $rows): array
    {
        return ['total_source_rows' => $rows->count(), 'student_rows' => $rows->where('type', 'STUDENT')->count(), 'staff_rows' => $rows->where('type', 'STAFF')->count(), 'unknown_rows' => $rows->where('type', 'UNKNOWN')->count(), 'student_matches' => $rows->where('type', 'STUDENT')->groupBy('match_status')->map->count()->all(), 'staff_matches' => $rows->where('type', 'STAFF')->groupBy('match_status')->map->count()->all()];
    }

    private function duplicateStudents(Collection $rows): array
    {
        return $rows->where('type', 'STUDENT')->whereNotNull('canonical_id')->groupBy('canonical_id')->filter(fn ($g) => $g->count() > 1)->map(fn ($g, $id) => ['student_id' => $id, 'rows' => $g->map(fn ($r) => [$r['source_file'], $r['source_row'], $r['route']])->values()->all()])->values()->all();
    }

    private function duplicateStaff(Collection $rows): array
    {
        return $rows->where('type', 'STAFF')->whereNotNull('canonical_id')->groupBy('canonical_id')->filter(fn ($g) => $g->count() > 1)->map(fn ($g, $id) => ['user_id' => $id, 'rows' => $g->map(fn ($r) => [$r['source_file'], $r['source_row']])->values()->all()])->values()->all();
    }

    private function pickupSuggestions(Collection $rows): array
    {
        return $rows->pluck('pickup_point')->filter()->groupBy(fn ($v) => $this->key($v))->filter(fn ($g) => $g->unique()->count() > 1)->map(fn ($g) => $g->unique()->values()->all())->values()->all();
    }

    private function dataConflicts(Collection $rows): array
    {
        $class = $rows->where('type', 'STUDENT')->whereNotNull('canonical_id')->groupBy('canonical_id')->filter(fn ($g) => $g->pluck('raw_class')->filter()->unique()->count() > 1)->map(fn ($g, $id) => ['student_id' => $id, 'classes' => $g->pluck('raw_class')->filter()->unique()->values()->all()])->values()->all();
        $pickup = $rows->where('type', 'STUDENT')->whereNotNull('canonical_id')->groupBy('canonical_id')->filter(fn ($g) => $g->pluck('pickup_point')->filter()->map(fn ($v) => $this->key($v))->unique()->count() > 1)->map(fn ($g, $id) => ['student_id' => $id, 'pickup_points' => $g->pluck('pickup_point')->filter()->unique()->values()->all()])->values()->all();
        $phones = $rows->where('type', 'STUDENT')->whereNotNull('phone')->filter(fn ($r) => filled($r['phone']))->groupBy(fn ($r) => preg_replace('/\D+/', '', (string) $r['phone']))->filter(fn ($g) => $g->pluck('canonical_id')->filter()->unique()->count() > 1)->map(fn ($g, $phone) => ['phone' => $phone, 'student_ids' => $g->pluck('canonical_id')->filter()->unique()->values()->all()])->values()->all();

        return ['class' => $class, 'pickup_point' => $pickup, 'phone' => $phones];
    }

    private function capacity(Collection $rows): array
    {
        return $rows->groupBy('route')->map(fn ($g, $route) => ['route' => $route, 'source_rows' => $g->count(), 'students' => $g->where('type', 'STUDENT')->count(), 'staff' => $g->where('type', 'STAFF')->count(), 'unknown' => $g->where('type', 'UNKNOWN')->count(), 'matched_students' => $g->where('type', 'STUDENT')->whereIn('match_status', ['EXACT', 'PROBABLE'])->count(), 'unmatched_students' => $g->where('type', 'STUDENT')->where('match_status', 'NOT_FOUND')->count(), 'ambiguous_students' => $g->where('type', 'STUDENT')->where('match_status', 'AMBIGUOUS')->count(), 'matched_staff' => $g->where('type', 'STAFF')->whereIn('match_status', ['EXACT', 'PROBABLE'])->count(), 'unmatched_staff' => $g->where('type', 'STAFF')->whereIn('match_status', ['NOT_FOUND', 'AMBIGUOUS'])->count(), 'student_capacity' => 14, 'status' => $g->where('type', 'STUDENT')->count() > 14 ? 'OVER_CAPACITY' : ($g->where('type', 'STUDENT')->count() === 14 ? 'FULL' : 'OK')])->values()->all();
    }
}
