<?php

namespace App\Services\Transport;

use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\StudentBootstrapImport;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** Source-keyed, preview-first bootstrap for the approved real transport assignments. */
class RealStudentTransportAssignmentBootstrapService
{
    public const TARGET_YEAR_ID = 1;

    public const EFFECTIVE_FROM = '2026-09-01';

    private const ROUTES = [
        'Арабия' => ['route_id' => 1, 'bus_id' => 1, 'vehicle_code' => '1', 'pricing_zone' => 'Zone 2', 'students' => 13],
        'Бествэй' => ['route_id' => 2, 'bus_id' => 2, 'vehicle_code' => '2', 'pricing_zone' => null, 'students' => 12],
        'Бритиш' => ['route_id' => 3, 'bus_id' => 3, 'vehicle_code' => '3', 'pricing_zone' => null, 'students' => 13],
        'Каусер' => ['route_id' => 4, 'bus_id' => 4, 'vehicle_code' => '4', 'pricing_zone' => 'Zone 1', 'students' => 14],
        'Эль Ахья' => ['route_id' => 5, 'bus_id' => 5, 'vehicle_code' => '5', 'pricing_zone' => 'Zone 3', 'students' => 12],
    ];

    public function __construct(
        private TransportImportPreviewService $workbooks,
        private TransportAssignmentService $assignments,
    ) {}

    public function defaultPaths(): array
    {
        $paths = glob(storage_path('app/transport-import/*.xlsx')) ?: [];
        sort($paths, SORT_STRING);

        return array_values($paths);
    }

    public function preview(array $paths = []): array
    {
        return $this->result('PREVIEW', $this->plan($paths ?: $this->defaultPaths()));
    }

    public function apply(User $actor, array $paths = []): array
    {
        abort_unless($actor->isActive() && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        return DB::transaction(function () use ($actor, $paths): array {
            $plan = $this->plan($paths ?: $this->defaultPaths());
            $created = 0;

            foreach ($plan as $item) {
                if ($item['assignment_exists']) {
                    continue;
                }

                $this->assignments->assign(
                    Enrollment::findOrFail($item['enrollment_id']),
                    TransportRoute::findOrFail($item['transport_route_id']),
                    Bus::findOrFail($item['bus_id']),
                    [
                        'pickup_point' => $item['pickup_point'],
                        'effective_from' => self::EFFECTIVE_FROM,
                        'effective_to' => null,
                        'change_reason' => 'Controlled real transport bootstrap: '.$item['source_key'],
                    ],
                    $actor,
                );
                $created++;
            }

            return $this->result('APPLY', $plan) + ['created_assignments' => $created];
        });
    }

    private function plan(array $paths): Collection
    {
        if (count($paths) !== 5) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly five approved XLSX source files.']);
        }

        $all = collect($paths)->flatMap(fn (string $path) => $this->workbooks->parseWorkbook($path));
        $types = $all->map(fn (array $row) => $this->workbooks->classify($row));
        $typeCounts = $types->countBy();
        if ($all->count() !== 75 || $typeCounts->get('STUDENT', 0) !== 64
            || $typeCounts->get('STAFF', 0) !== 11 || $typeCounts->get('UNKNOWN', 0) !== 0) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly 64 student rows, 11 staff rows, and no unknown rows.']);
        }

        $rows = $all->zip($types)->filter(fn (Collection $pair) => $pair[1] === 'STUDENT')->map(fn (Collection $pair) => $pair[0])->values();
        $routeCounts = $rows->countBy(fn (array $row) => $this->canonical((string) $row['route']));
        foreach (self::ROUTES as $name => $mapping) {
            if ($routeCounts->get($name, 0) !== $mapping['students']) {
                throw ValidationException::withMessages(['sources' => "Route {$name} must contain exactly {$mapping['students']} student rows."]);
            }
        }
        if ($routeCounts->count() !== count(self::ROUTES)) {
            throw ValidationException::withMessages(['sources' => 'An unapproved route entered the student assignment flow.']);
        }

        $plan = $rows->map(function (array $row): array {
            $routeName = $this->canonical((string) $row['route']);
            $mapping = self::ROUTES[$routeName] ?? null;
            if (! $mapping) {
                throw ValidationException::withMessages(['route' => "No approved mapping for {$routeName}."]);
            }

            $sourceKey = hash('sha256', json_encode([
                $row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name'],
            ], JSON_UNESCAPED_UNICODE));
            $imports = StudentBootstrapImport::query()->where('source_key', $sourceKey)->get();
            if ($imports->count() !== 1) {
                throw ValidationException::withMessages(['source_key' => "Source row {$row['source_file']}:{$row['source_row']} has no unique bootstrap metadata."]);
            }
            $import = $imports->first();
            if ($import->source_file !== $row['source_file'] || $import->source_sheet !== $row['source_sheet']
                || (int) $import->source_row !== (int) $row['source_row'] || $import->raw_full_name !== $row['raw_full_name']
                || $this->canonical($import->route) !== $routeName || $import->raw_pickup_point !== $row['raw_pickup_point']) {
                throw ValidationException::withMessages(['source_key' => "Bootstrap metadata conflicts with source row {$row['source_file']}:{$row['source_row']}."]);
            }

            $enrollment = Enrollment::query()->with('student')->find($import->enrollment_id);
            if (! $enrollment || ! $enrollment->is_active || $enrollment->status !== 'active'
                || (int) $enrollment->academic_year_id !== self::TARGET_YEAR_ID
                || (int) $enrollment->student_id !== (int) $import->student_id || ! $enrollment->student) {
                throw ValidationException::withMessages(['enrollment' => "Inactive, missing, or conflicting enrollment for source key {$sourceKey}."]);
            }

            [$route, $bus] = $this->canonicalTransport($routeName, $mapping);
            $overlaps = StudentTransportAssignment::query()->where('enrollment_id', $enrollment->id)
                ->whereDate('effective_from', '<=', '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', self::EFFECTIVE_FROM))
                ->get();
            $exact = $overlaps->filter(fn (StudentTransportAssignment $assignment) => (int) $assignment->transport_route_id === $route->id
                && (int) $assignment->bus_id === $bus->id
                && $assignment->pickup_point === $row['raw_pickup_point']
                && $assignment->effective_from?->toDateString() === self::EFFECTIVE_FROM
                && $assignment->effective_to === null
                && $assignment->status === StudentTransportAssignment::STATUS_ACTIVE
                && $assignment->pricing_zone === $route->pricing_zone
            );
            if ($overlaps->count() > 1 || ($overlaps->isNotEmpty() && $exact->count() !== 1)) {
                throw ValidationException::withMessages(['assignment' => "Conflicting overlapping assignment for enrollment {$enrollment->id}."]);
            }

            return [
                'source_key' => $sourceKey,
                'source_file' => $row['source_file'],
                'source_sheet' => $row['source_sheet'],
                'source_row' => (int) $row['source_row'],
                'raw_full_name' => $row['raw_full_name'],
                'student_id' => (int) $import->student_id,
                'enrollment_id' => (int) $import->enrollment_id,
                'route' => $routeName,
                'transport_route_id' => $route->id,
                'bus_id' => $bus->id,
                'vehicle_code' => $bus->vehicle_code,
                'pickup_point' => $row['raw_pickup_point'],
                'effective_from' => self::EFFECTIVE_FROM,
                'effective_to' => null,
                'assignment_exists' => $exact->count() === 1,
            ];
        });

        if ($plan->count() !== 64 || $plan->pluck('source_key')->unique()->count() !== 64
            || $plan->pluck('enrollment_id')->unique()->count() !== 64) {
            throw ValidationException::withMessages(['bootstrap' => 'Expected 64 unique source keys and enrollments.']);
        }

        foreach (self::ROUTES as $name => $mapping) {
            $existing = StudentTransportAssignment::query()->where('bus_id', $mapping['bus_id'])
                ->whereDate('effective_from', '<=', '2027-06-30')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', self::EFFECTIVE_FROM))->count();
            $new = $plan->where('route', $name)->where('assignment_exists', false)->count();
            if ($existing + $new > 14) {
                throw ValidationException::withMessages(['capacity' => "Approved plan would exceed 14 student seats on {$name}."]);
            }
        }

        return $plan;
    }

    private function canonicalTransport(string $name, array $mapping): array
    {
        $route = TransportRoute::find($mapping['route_id']);
        $bus = Bus::find($mapping['bus_id']);
        if (! $route || ! $bus || $this->canonical($route->name) !== $name
            || $route->pricing_zone !== $mapping['pricing_zone'] || ! $route->is_active
            || $bus->vehicle_code !== $mapping['vehicle_code'] || ! $bus->is_active
            || (int) $bus->transport_route_id !== $route->id || (int) $bus->student_capacity !== 14) {
            throw ValidationException::withMessages(['mapping' => "Canonical route/bus mapping conflicts for {$name}."]);
        }

        return [$route, $bus];
    }

    private function result(string $mode, Collection $plan): array
    {
        return [
            'mode' => $mode,
            'expected_assignments' => 64,
            'new_assignments' => $plan->where('assignment_exists', false)->count(),
            'existing_assignments' => $plan->where('assignment_exists', true)->count(),
            'by_route' => $plan->groupBy('route')->map->count()->all(),
            'rows' => $plan->values()->all(),
        ];
    }

    private function canonical(string $value): string
    {
        return class_exists(Normalizer::class) ? Normalizer::normalize($value, Normalizer::FORM_C) : $value;
    }
}
