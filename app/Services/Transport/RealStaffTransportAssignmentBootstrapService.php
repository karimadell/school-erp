<?php

namespace App\Services\Transport;

use App\Models\Bus;
use App\Models\StaffMember;
use App\Models\StaffTransportBootstrapImport;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** Preview-first, source-keyed bootstrap for real staff passengers. */
class RealStaffTransportAssignmentBootstrapService
{
    public const EFFECTIVE_FROM = '2026-09-01';

    private const ROUTES = [
        'Арабия' => ['route_id' => 1, 'bus_id' => 1, 'vehicle_code' => '1', 'staff' => 2],
        'Бествэй' => ['route_id' => 2, 'bus_id' => 2, 'vehicle_code' => '2', 'staff' => 3],
        'Бритиш' => ['route_id' => 3, 'bus_id' => 3, 'vehicle_code' => '3', 'staff' => 2],
        'Каусер' => ['route_id' => 4, 'bus_id' => 4, 'vehicle_code' => '4', 'staff' => 1],
        'Эль Ахья' => ['route_id' => 5, 'bus_id' => 5, 'vehicle_code' => '5', 'staff' => 3],
    ];

    private const DAYS = ['пн' => 1, 'вт' => 2, 'ср' => 3, 'чт' => 4, 'пт' => 5, 'сб' => 6, 'вс' => 7];

    public function __construct(
        private TransportImportPreviewService $workbooks,
        private VehicleStaffAssignmentService $assignments,
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
            $createdMembers = 0;
            foreach ($plan as $item) {
                if ($item['assignment_exists']) {
                    continue;
                }
                $metadata = StaffTransportBootstrapImport::query()->where('source_key', $item['source_key'])->lockForUpdate()->first();
                if (! $metadata) {
                    $member = StaffMember::create(['display_name' => $item['display_name'], 'is_active' => true]);
                    $metadata = StaffTransportBootstrapImport::create([
                        'source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'],
                        'source_row' => $item['source_row'], 'raw_display_name' => $item['raw_full_name'], 'raw_pickup_point' => $item['raw_pickup_point'],
                        'raw_contact' => $item['raw_phone'], 'raw_notes' => $item['raw_notes'], 'route' => $item['route'], 'staff_member_id' => $member->id,
                    ]);
                    $createdMembers++;
                }
                $assignment = $this->assignments->assign(
                    Bus::findOrFail($item['bus_id']),
                    $metadata->staffMember,
                    $item['proposed_role'],
                    self::EFFECTIVE_FROM,
                    null,
                    $item['weekdays'],
                    $actor,
                    $item['source_trace'],
                );
                $metadata->update(['vehicle_staff_assignment_id' => $assignment->id]);
                $created++;
            }

            return $this->result('APPLY', $plan) + ['created_staff_members' => $createdMembers, 'created_assignments' => $created];
        });
    }

    private function plan(array $paths): Collection
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

        $rows = $all->zip($types)->filter(fn (Collection $pair) => $pair[1] === 'STAFF')->map(fn (Collection $pair) => $pair[0])->values();
        $routeCounts = $rows->countBy(fn (array $row) => $this->canonical((string) $row['route']));
        foreach (self::ROUTES as $name => $mapping) {
            if ($routeCounts->get($name, 0) !== $mapping['staff']) {
                throw ValidationException::withMessages(['sources' => "Route {$name} must contain exactly {$mapping['staff']} staff rows."]);
            }
        }
        if ($routeCounts->count() !== 5) {
            throw ValidationException::withMessages(['sources' => 'An unapproved route entered the staff flow.']);
        }

        $plan = $rows->map(function (array $row): array {
            $routeName = $this->canonical((string) $row['route']);
            $mapping = self::ROUTES[$routeName];
            [$route, $bus] = $this->canonicalTransport($routeName, $mapping);
            $weekdays = $this->weekdays($row['raw_full_name'].' '.$row['raw_notes']);
            $sourceKey = hash('sha256', json_encode([$row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name']], JSON_UNESCAPED_UNICODE));
            $sourceTrace = $this->sourceTrace($row, $sourceKey);
            $role = VehicleStaffAssignment::ROLE_STAFF_PASSENGER;
            $metadata = StaffTransportBootstrapImport::query()->with(['staffMember', 'vehicleStaffAssignment'])->where('source_key', $sourceKey)->first();
            if (! $metadata && VehicleStaffAssignment::query()->where('change_reason', $sourceTrace)->exists()) {
                throw ValidationException::withMessages(['source' => "Legacy source-traced assignment {$sourceKey} requires explicit identity migration before bootstrap."]);
            }
            if ($metadata) {
                $this->validateMetadata($metadata, $row, $routeName);
            }
            $assignment = $metadata?->vehicleStaffAssignment;
            $exact = $assignment && (int) $assignment->bus_id === $bus->id && (int) $assignment->staff_member_id === $metadata->staff_member_id
                && $assignment->role === $role && $assignment->effective_from?->toDateString() === self::EFFECTIVE_FROM
                && $assignment->effective_to === null && $assignment->weekdays === $weekdays;
            if ($assignment && ! $exact) {
                throw ValidationException::withMessages(['assignment' => "Conflicting source-keyed staff assignment for {$sourceKey}."]);
            }

            return $row + [
                'identity_status' => $metadata ? 'MATCHED' : 'PROPOSED',
                'staff_member_id' => $metadata?->staff_member_id,
                'display_name' => trim((string) preg_replace('/\s*\([^)]*\)\s*/u', '', $row['raw_full_name'])),
                'source_key' => $sourceKey,
                'source_trace' => $sourceTrace,
                'route' => $routeName,
                'route_id' => $route->id,
                'bus_id' => $bus->id,
                'vehicle_code' => $bus->vehicle_code,
                'weekdays' => $weekdays,
                'proposed_role' => $role,
                'effective_from' => self::EFFECTIVE_FROM,
                'effective_to' => null,
                'assignment_exists' => (bool) $exact,
            ];
        });

        if ($plan->count() !== 11 || $plan->pluck('source_key')->unique()->count() !== 11) {
            throw ValidationException::withMessages(['sources' => 'Expected 11 unique staff source identities.']);
        }
        $this->assertCapacity($plan);

        return $plan;
    }

    private function validateMetadata(StaffTransportBootstrapImport $metadata, array $row, string $route): void
    {
        foreach (['source_file', 'source_sheet', 'source_row'] as $field) {
            if ((string) $metadata->{$field} !== (string) $row[$field]) {
                throw ValidationException::withMessages(['source' => 'Source metadata conflict.']);
            }
        }
        if ($metadata->raw_display_name !== $row['raw_full_name'] || $metadata->raw_pickup_point !== $row['raw_pickup_point'] || $metadata->raw_contact !== $row['raw_phone'] || $metadata->raw_notes !== $row['raw_notes'] || $metadata->route !== $route || ! $metadata->staffMember?->is_active) {
            throw ValidationException::withMessages(['source' => 'Source metadata or StaffMember conflict.']);
        }
    }

    private function weekdays(string $value): ?array
    {
        preg_match('/\(([^)]*)\)/u', $value, $match);
        if (! isset($match[1])) {
            return null;
        }
        $days = collect(preg_split('/\s*,\s*/u', $match[1]))->map(fn (string $day) => self::DAYS[$this->key($day)] ?? null)->filter()->unique()->sort()->values()->all();

        return $days === [] ? null : $days;
    }

    private function assertCapacity(Collection $plan): void
    {
        foreach ($this->capacityAnalysis($plan) as $analysis) {
            if ($analysis['peak_physical_occupancy'] > $analysis['passenger_capacity']) {
                throw ValidationException::withMessages(['capacity' => "Physical occupancy exceeds {$analysis['passenger_capacity']} on {$analysis['route']}."]);
            }
        }
    }

    private function capacityAnalysis(Collection $plan): array
    {
        return collect(self::ROUTES)->map(function (array $mapping, string $name) use ($plan): array {
            $bus = Bus::findOrFail($mapping['bus_id']);
            $students = StudentTransportAssignment::query()->where('bus_id', $bus->id)->where('status', 'active')->whereNull('effective_to')->count();
            $existing = VehicleStaffAssignment::query()->where('bus_id', $bus->id)
                ->whereDate('effective_from', '<=', '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', self::EFFECTIVE_FROM))->get();
            $byDay = collect(range(1, 7))->mapWithKeys(function (int $day) use ($existing, $plan, $bus): array {
                $existingCount = $existing->filter(fn (VehicleStaffAssignment $item) => $item->role !== VehicleStaffAssignment::ROLE_DRIVER && ($item->weekdays === null || in_array($day, $item->weekdays, true)))->count();
                $newCount = $plan->where('bus_id', $bus->id)->where('assignment_exists', false)
                    ->filter(fn (array $item) => $item['weekdays'] === null || in_array($day, $item['weekdays'], true))->count();

                return [$day => $existingCount + $newCount];
            });

            return [
                'bus_id' => $bus->id, 'route' => $name, 'students' => $students,
                'staff_by_iso_weekday' => $byDay->all(), 'peak_staff' => $byDay->max(),
                'peak_physical_occupancy' => $students + $byDay->max(),
                'passenger_capacity' => $bus->passenger_capacity,
            ];
        })->values()->all();
    }

    private function canonicalTransport(string $name, array $mapping): array
    {
        $route = TransportRoute::find($mapping['route_id']);
        $bus = Bus::find($mapping['bus_id']);
        if (! $route || ! $bus || $this->canonical($route->name) !== $name || ! $route->is_active || ! $bus->is_active
            || $bus->vehicle_code !== $mapping['vehicle_code'] || (int) $bus->transport_route_id !== $route->id) {
            throw ValidationException::withMessages(['mapping' => "Canonical route/bus mapping conflicts for {$name}."]);
        }

        return [$route, $bus];
    }

    private function result(string $mode, Collection $plan): array
    {
        return [
            'mode' => $mode,
            'expected_staff' => 11,
            'proposed_staff_members' => $plan->where('identity_status', 'PROPOSED')->count(),
            'identity_summary' => $plan->countBy('identity_status')->all(),
            'new_assignments' => $plan->where('assignment_exists', false)->count(),
            'existing_assignments' => $plan->where('assignment_exists', true)->count(),
            'capacity' => $this->capacityAnalysis($plan),
            'rows' => $plan->values()->all(),
        ];
    }

    private function sourceTrace(array $row, string $sourceKey): string
    {
        return 'Controlled real staff transport bootstrap: '.json_encode([
            'source_key' => $sourceKey,
            'file' => $row['source_file'],
            'sheet' => $row['source_sheet'],
            'row' => (int) $row['source_row'],
            'raw_identity' => $row['raw_full_name'],
            'raw_pickup' => $row['raw_pickup_point'],
            'raw_contact' => $row['raw_phone'],
            'raw_notes' => $row['raw_notes'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private function key(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $this->canonical((string) $value))));
    }

    private function canonical(string $value): string
    {
        return class_exists(Normalizer::class) ? Normalizer::normalize($value, Normalizer::FORM_C) : $value;
    }
}
