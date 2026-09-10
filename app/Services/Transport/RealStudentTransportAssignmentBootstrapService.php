<?php

namespace App\Services\Transport;

use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\MasterStudentImport;
use App\Models\Student;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\MasterData\MasterStudentImportService;
use App\Support\RouteNameNormalizer;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Plans authoritative current transport assignments without bootstrap identity rows. */
class RealStudentTransportAssignmentBootstrapService
{
    public const TARGET_YEAR_ID = 1;

    public const EFFECTIVE_FROM = '2026-09-01';

    private const ROUTES = [
        'Арабия' => ['vehicle_code' => '1', 'pricing_zone' => 'Zone 2', 'students' => 13],
        'Бествэй' => ['vehicle_code' => '2', 'pricing_zone' => null, 'students' => 12],
        'Бритиш' => ['vehicle_code' => '3', 'pricing_zone' => null, 'students' => 13],
        'Каусер' => ['vehicle_code' => '4', 'pricing_zone' => 'Zone 1', 'students' => 14],
        'Эль Ахья' => ['vehicle_code' => '5', 'pricing_zone' => 'Zone 3', 'students' => 12],
    ];

    public function __construct(
        private TransportImportPreviewService $workbooks,
        private TransportAssignmentService $assignments,
        private MasterStudentImportService $masterStudents,
    ) {}

    public function defaultPaths(): array
    {
        $paths = glob(storage_path('app/transport-import/*.xlsx')) ?: [];
        sort($paths, SORT_STRING);

        return array_values($paths);
    }

    public function preview(array $paths = [], ?string $masterPath = null): array
    {
        return $this->result('PREVIEW', $this->plan($paths ?: $this->defaultPaths(), $masterPath));
    }

    public function apply(User $actor, array $paths = [], ?string $masterPath = null): array
    {
        abort_unless($actor->isActive() && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        return DB::transaction(function () use ($actor, $paths, $masterPath): array {
            $plan = $this->plan($paths ?: $this->defaultPaths(), $masterPath);
            $created = 0;
            foreach ($plan['current'] as $item) {
                if ($item['assignment_exists']) {
                    continue;
                }
                $enrollment = $this->resolveEnrollmentForApply($item);
                [$route, $bus] = $this->resolveCatalogForApply($item);
                $this->assignments->assign($enrollment, $route, $bus, [
                    'pickup_point' => $item['pickup_point'], 'effective_from' => self::EFFECTIVE_FROM,
                    'effective_to' => null, 'change_reason' => $item['source_trace'],
                ], $actor);
                $created++;
            }

            return $this->result('APPLY', $plan) + ['created_assignments' => $created];
        });
    }

    private function plan(array $paths, ?string $masterPath): array
    {
        if (count($paths) !== 5) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly five approved XLSX source files.']);
        }
        $all = collect($paths)->flatMap(fn (string $path) => $this->workbooks->parseWorkbook($path));
        $types = $all->map(fn (array $row) => $this->workbooks->classify($row));
        $counts = $types->countBy();
        if ($all->count() !== 75 || $counts->get('STUDENT', 0) !== 64 || $counts->get('STAFF', 0) !== 11 || $counts->get('UNKNOWN', 0) !== 0) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly 64 student rows, 11 staff rows, and no unknown rows.']);
        }
        $rows = $all->zip($types)->filter(fn (Collection $pair) => $pair[1] === 'STUDENT')->map(fn (Collection $pair) => $pair[0])->values();
        foreach (self::ROUTES as $name => $mapping) {
            if ($rows->filter(fn ($row) => $this->key($row['route']) === $this->key($name))->count() !== $mapping['students']) {
                throw ValidationException::withMessages(['sources' => "Route {$name} has an unexpected student count."]);
            }
        }

        $identities = $this->studentIdentityPlan($masterPath);
        $current = collect();
        $history = collect();
        foreach ($rows as $row) {
            $routeName = RouteNameNormalizer::canonicalName($row['route']);
            $sourceKey = $this->sourceKey($row);
            $identity = $this->resolveIdentity($identities, $row['raw_full_name']);
            if ($row['raw_full_name'] === 'Денисенко Александра' && $routeName === 'Бритиш') {
                $history->push($row + [
                    'source_key' => $sourceKey, 'student_planning_key' => $identity['planning_key'],
                    'canonical_name' => $identity['canonical_name'], 'route' => $routeName,
                    'assignment_semantics' => 'HISTORICAL_SOURCE_EVIDENCE', 'current' => false,
                    'history_reason' => 'Confirmed historical Бритиш evidence; current route is Эль Ахья',
                ]);

                continue;
            }
            $catalog = $this->catalogReference($routeName);
            $enrollmentId = $identity['enrollment_id'];
            $overlaps = $enrollmentId ? StudentTransportAssignment::query()->where('enrollment_id', $enrollmentId)
                ->whereDate('effective_from', '<=', '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', self::EFFECTIVE_FROM))->get() : collect();
            $exact = $overlaps->filter(fn ($assignment) => $catalog['route_id'] && $catalog['bus_id']
                && (int) $assignment->transport_route_id === (int) $catalog['route_id'] && (int) $assignment->bus_id === (int) $catalog['bus_id']
                && $assignment->pickup_point === $row['raw_pickup_point'] && $assignment->effective_from?->toDateString() === self::EFFECTIVE_FROM
                && $assignment->effective_to === null && $assignment->status === StudentTransportAssignment::STATUS_ACTIVE);
            if ($overlaps->count() > 1 || ($overlaps->isNotEmpty() && $exact->count() !== 1)) {
                throw ValidationException::withMessages(['assignment' => "Conflicting assignment for {$identity['canonical_name']}."]);
            }
            $trace = 'Authoritative master transport: '.$sourceKey;
            $current->push($row + $identity + $catalog + [
                'source_key' => $sourceKey, 'student_planning_key' => $identity['planning_key'],
                'route' => $routeName, 'pickup_point' => $row['raw_pickup_point'],
                'effective_from' => self::EFFECTIVE_FROM, 'effective_to' => null, 'current' => true,
                'assignment_semantics' => 'CURRENT', 'source_trace' => $trace, 'assignment_exists' => $exact->count() === 1,
            ]);
        }
        if ($current->count() !== 63 || $current->pluck('student_planning_key')->unique()->count() !== 63 || $history->count() !== 1) {
            throw ValidationException::withMessages(['identity' => 'Expected 63 unique current identities and one approved historical Денисенко row.']);
        }
        foreach (self::ROUTES as $name => $mapping) {
            if ($current->filter(fn ($row) => $this->key($row['route']) === $this->key($name))->count() > 14) {
                throw ValidationException::withMessages(['capacity' => "Approved plan exceeds 14 student seats on {$name}."]);
            }
        }

        return ['current' => $current, 'history' => $history];
    }

    private function studentIdentityPlan(?string $masterPath): Collection
    {
        if ($masterPath !== null && is_file($masterPath)) {
            $preview = $this->masterStudents->preview($masterPath);
            if ($preview['review_required'] !== []) {
                throw ValidationException::withMessages(['identity' => 'Master Student preview contains REVIEW_REQUIRED identities.']);
            }

            return collect($preview['rows'])->map(fn ($row) => [
                'planning_key' => $row['planning_key'], 'master_source_key' => $row['source_key'],
                'canonical_name' => $row['canonical_name'], 'aliases' => $row['source_aliases'],
                'student_id' => $row['student_id'], 'enrollment_id' => $row['enrollment_id'],
            ]);
        }

        // Portable legacy-test compatibility: exact existing identities only; never bootstrap metadata.
        return Student::query()->whereNull('merged_into_student_id')->with(['enrollments' => fn ($q) => $q->where('academic_year_id', self::TARGET_YEAR_ID)->where('is_active', true)])->get()
            ->groupBy(fn ($student) => $this->key($student->name))->map(function ($matches, $name) {
                if ($matches->count() > 1 && $name !== $this->key('Денисенко Александра')) {
                    throw ValidationException::withMessages(['identity' => "Existing Student identity {$name} is ambiguous."]);
                }
                $student = $matches->last();
                if ($student->enrollments->count() !== 1) {
                    throw ValidationException::withMessages(['identity' => "Existing Student {$student->name} has no unique active AY1 enrollment."]);
                }

                return ['planning_key' => 'existing-student:'.$student->id, 'master_source_key' => null,
                    'canonical_name' => $student->name, 'aliases' => [], 'student_id' => $student->id,
                    'enrollment_id' => $student->enrollments->first()->id];
            })->values();
    }

    private function resolveIdentity(Collection $identities, string $sourceName): array
    {
        $key = $this->key($sourceName === 'Эльшейх Адам' ? 'Эльшейх Сухайб' : $sourceName);
        $matches = $identities->filter(fn ($item) => $this->key($item['canonical_name']) === $key
            || collect($item['aliases'])->contains(fn ($alias) => $this->key($alias) === $this->key($sourceName)))->values();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['identity' => "Transport identity {$sourceName} has {$matches->count()} canonical master matches."]);
        }

        return $matches->first();
    }

    private function catalogReference(string $name): array
    {
        $mapping = self::ROUTES[$name] ?? throw ValidationException::withMessages(['route' => "Unknown route {$name}."]);
        $routes = TransportRoute::query()->get()->filter(fn ($route) => $this->key($route->name) === $this->key($name))->values();
        $buses = Bus::query()->where('vehicle_code', $mapping['vehicle_code'])->get();
        if ($routes->count() > 1 || $buses->count() > 1) {
            throw ValidationException::withMessages(['mapping' => "Canonical catalog reference {$name} is ambiguous."]);
        }
        $route = $routes->first();
        $bus = $buses->first();
        if ($route && (! $route->is_active || $route->pricing_zone !== $mapping['pricing_zone'])) {
            throw ValidationException::withMessages(['mapping' => "Canonical route {$name} conflicts."]);
        }
        if ($bus && (! $bus->is_active || (int) $bus->student_capacity !== 14 || (int) $bus->passenger_capacity !== 15
            || ($route && (int) $bus->transport_route_id !== (int) $route->id))) {
            throw ValidationException::withMessages(['mapping' => "Canonical bus {$mapping['vehicle_code']} conflicts."]);
        }

        return ['route_planning_key' => 'route:'.$name, 'bus_planning_key' => 'bus:'.$mapping['vehicle_code'],
            'transport_route_id' => $route?->id, 'route_id' => $route?->id, 'bus_id' => $bus?->id,
            'vehicle_code' => $mapping['vehicle_code'], 'pricing_zone' => $mapping['pricing_zone'],
            'catalog_status' => $route && $bus ? 'REUSE' : 'PLANNED'];
    }

    private function resolveEnrollmentForApply(array $item): Enrollment
    {
        if ($item['master_source_key']) {
            $meta = MasterStudentImport::query()->where('source_key', $item['master_source_key'])->lockForUpdate()->first();
            if (! $meta?->enrollment_id || ! $meta->enrollment?->is_active) {
                throw ValidationException::withMessages(['dependency' => 'Master Student and formal enrollment must be applied before Transport assignments.']);
            }

            return $meta->enrollment;
        }

        return Enrollment::query()->lockForUpdate()->findOrFail($item['enrollment_id']);
    }

    private function resolveCatalogForApply(array $item): array
    {
        $routes = TransportRoute::query()->lockForUpdate()->get()->filter(fn ($route) => $this->key($route->name) === $this->key($item['route']))->values();
        if ($routes->count() !== 1) {
            throw ValidationException::withMessages(['dependency' => 'Canonical route must be uniquely applied before assignments.']);
        }
        $route = $routes->first();
        $bus = Bus::query()->where('vehicle_code', $item['vehicle_code'])->lockForUpdate()->sole();
        if ((int) $bus->transport_route_id !== (int) $route->id) {
            throw ValidationException::withMessages(['dependency' => 'Canonical route/bus catalog must be applied before assignments.']);
        }

        return [$route, $bus];
    }

    private function result(string $mode, array $plan): array
    {
        $current = $plan['current'];

        return ['mode' => $mode, 'expected_assignments' => 63, 'new_assignments' => $current->where('assignment_exists', false)->count(),
            'existing_assignments' => $current->where('assignment_exists', true)->count(),
            'by_route' => $current->groupBy(fn ($row) => $this->key($row['route']))
                ->mapWithKeys(fn ($rows) => [RouteNameNormalizer::canonicalName($rows->first()['route']) => $rows->count()])->all(),
            'historical_evidence' => $plan['history']->values()->all(), 'rows' => $current->values()->all()];
    }

    private function sourceKey(array $row): string
    {
        return hash('sha256', json_encode([$row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name']], JSON_UNESCAPED_UNICODE));
    }

    private function key(string $value): string
    {
        return str_replace('ё', 'е', RouteNameNormalizer::key($value));
    }
}
