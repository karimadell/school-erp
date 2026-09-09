<?php

namespace App\Services\Transport;

use App\Models\Bus;
use App\Models\StaffMasterImport;
use App\Models\StaffMember;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\MasterData\MasterStaffImportService;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** Plans authoritative staff-passenger assignments without bootstrap identity rows. */
class RealStaffTransportAssignmentBootstrapService
{
    public const EFFECTIVE_FROM = '2026-09-01';

    private const DAYS = ['пн' => 1, 'вт' => 2, 'ср' => 3, 'чт' => 4, 'пт' => 5, 'сб' => 6, 'вс' => 7];

    private const ROUTES = [
        'Арабия' => ['vehicle_code' => '1', 'staff' => 2], 'Бествэй' => ['vehicle_code' => '2', 'staff' => 3],
        'Бритиш' => ['vehicle_code' => '3', 'staff' => 2], 'Каусер' => ['vehicle_code' => '4', 'staff' => 1],
        'Эль Ахья' => ['vehicle_code' => '5', 'staff' => 3],
    ];

    public function __construct(
        private TransportImportPreviewService $workbooks,
        private VehicleStaffAssignmentService $assignments,
        private MasterStaffImportService $masterStaff,
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
            $created = $createdMembers = 0;
            foreach ($plan as $item) {
                if ($item['assignment_exists']) {
                    continue;
                }
                $member = $this->resolveStaffForApply($item, $createdMembers);
                $bus = Bus::query()->where('vehicle_code', $item['vehicle_code'])->lockForUpdate()->sole();
                $routes = TransportRoute::query()->lockForUpdate()->get()->filter(fn ($route) => $this->key($route->name) === $this->key($item['route']))->values();
                if ($routes->count() !== 1) {
                    throw ValidationException::withMessages(['dependency' => 'Canonical route must be uniquely applied before staff assignments.']);
                }
                $route = $routes->first();
                if ((int) $bus->transport_route_id !== (int) $route->id) {
                    throw ValidationException::withMessages(['dependency' => 'Canonical route/bus catalog must be applied before staff assignments.']);
                }
                $this->assignments->assign($bus, $member, $item['proposed_role'], self::EFFECTIVE_FROM, null, $item['weekdays'], $actor, $item['source_trace']);
                $created++;
            }

            return $this->result('APPLY', $plan) + ['created_staff_members' => $createdMembers, 'created_assignments' => $created];
        });
    }

    private function plan(array $paths, ?string $masterPath): Collection
    {
        if (count($paths) !== 5) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly five approved XLSX source files.']);
        }
        $all = collect($paths)->flatMap(fn ($path) => $this->workbooks->parseWorkbook($path));
        $types = $all->map(fn ($row) => $this->workbooks->classify($row));
        $counts = $types->countBy();
        if ($all->count() !== 75 || $counts->get('STUDENT', 0) !== 64 || $counts->get('STAFF', 0) !== 11 || $counts->get('UNKNOWN', 0) !== 0) {
            throw ValidationException::withMessages(['sources' => 'Expected exactly 64 student rows, 11 staff rows, and no unknown rows.']);
        }
        $rows = $all->zip($types)->filter(fn (Collection $pair) => $pair[1] === 'STAFF')->map(fn (Collection $pair) => $pair[0])->values();
        foreach (self::ROUTES as $name => $spec) {
            if ($rows->filter(fn ($r) => $this->canonical($r['route']) === $name)->count() !== $spec['staff']) {
                throw ValidationException::withMessages(['sources' => "Route {$name} has an unexpected staff count."]);
            }
        }
        $masterLinks = $this->masterLinks($paths, $masterPath);
        $plan = $rows->map(function ($row) use ($masterLinks) {
            $sourceKey = $this->sourceKey($row);
            $route = $this->canonical($row['route']);
            $spec = self::ROUTES[$route];
            $link = $masterLinks->get($sourceKey);
            if (! $link) {
                throw ValidationException::withMessages(['identity' => "No canonical StaffMember identity for {$row['raw_full_name']}."]);
            }
            $catalog = $this->catalogReference($route, $spec['vehicle_code']);
            $trace = $this->sourceTrace($row, $sourceKey);
            $existing = VehicleStaffAssignment::query()->where('change_reason', $trace)->get();
            if ($existing->count() > 1) {
                throw ValidationException::withMessages(['assignment' => "Duplicate source-traced staff assignment {$sourceKey}."]);
            }
            if ($existing->first() && ($catalog['bus_id'] === null || $existing->first()->user_id !== null
                || $existing->first()->staff_member_id === null || (int) $existing->first()->bus_id !== (int) $catalog['bus_id']
                || ($link['staff_member_id'] !== null && (int) $existing->first()->staff_member_id !== (int) $link['staff_member_id']))) {
                throw ValidationException::withMessages(['assignment' => "Conflicting source-traced staff assignment {$sourceKey}."]);
            }

            return $row + $link + $catalog + ['source_key' => $sourceKey, 'source_trace' => $trace, 'route' => $route,
                'weekdays' => $this->weekdays($row['raw_full_name'].' '.$row['raw_notes']),
                'proposed_role' => VehicleStaffAssignment::ROLE_STAFF_PASSENGER, 'effective_from' => self::EFFECTIVE_FROM,
                'effective_to' => null, 'assignment_exists' => $existing->count() === 1];
        });
        if ($plan->count() !== 11 || $plan->pluck('staff_planning_key')->unique()->count() !== 11) {
            throw ValidationException::withMessages(['identity' => 'Expected 11 unique canonical staff assignment identities.']);
        }
        $this->assertCapacity($plan);

        return $plan;
    }

    private function masterLinks(array $paths, ?string $masterPath): Collection
    {
        if ($masterPath !== null && is_file($masterPath)) {
            $preview = $this->masterStaff->preview($masterPath, $paths);

            return collect($preview['transport_links'])->mapWithKeys(fn ($link) => [$link['source_key'] => [
                'identity_status' => $link['staff_member_id'] ? 'MATCHED' : 'PLANNED_MASTER',
                'staff_planning_key' => 'master-staff:'.$link['master_source_key'], 'master_source_key' => $link['master_source_key'],
                'staff_member_id' => $link['staff_member_id'], 'display_name' => $link['master_name'],
            ]]);
        }

        return collect($paths)->flatMap(fn ($p) => $this->workbooks->parseWorkbook($p))->filter(fn ($r) => $this->workbooks->classify($r) === 'STAFF')
            ->mapWithKeys(function ($row) {
                $key = $this->sourceKey($row);
                $display = trim(preg_replace('/\s*\([^)]*\)\s*/u', '', $row['raw_full_name']));
                $matches = StaffMember::query()->where('display_name', $display)->get();
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages(['identity' => "Ambiguous StaffMember {$display}."]);
                }

                return [$key => ['identity_status' => $matches->count() ? 'MATCHED' : 'PROPOSED', 'staff_planning_key' => 'transport-staff:'.$key,
                    'master_source_key' => null, 'staff_member_id' => $matches->first()?->id, 'display_name' => $display]];
            });
    }

    private function catalogReference(string $routeName, string $vehicleCode): array
    {
        $routes = TransportRoute::query()->get()->filter(fn ($route) => $this->key($route->name) === $this->key($routeName))->values();
        $buses = Bus::query()->where('vehicle_code', $vehicleCode)->get();
        if ($routes->count() > 1 || $buses->count() > 1) {
            throw ValidationException::withMessages(['mapping' => "Ambiguous catalog {$routeName}."]);
        }
        $route = $routes->first();
        $bus = $buses->first();
        if ($bus && ((int) $bus->student_capacity !== 14 || (int) $bus->passenger_capacity !== 15 || ! $bus->is_active)) {
            throw ValidationException::withMessages(['capacity' => "Bus {$vehicleCode} is not 14/15 active capacity."]);
        }

        return ['route_planning_key' => 'route:'.$routeName, 'bus_planning_key' => 'bus:'.$vehicleCode,
            'route_id' => $route?->id, 'bus_id' => $bus?->id, 'vehicle_code' => $vehicleCode, 'catalog_status' => $route && $bus ? 'REUSE' : 'PLANNED'];
    }

    private function resolveStaffForApply(array $item, int &$created): StaffMember
    {
        if ($item['master_source_key']) {
            $meta = StaffMasterImport::query()->where('source_key', $item['master_source_key'])->lockForUpdate()->first();
            if (! $meta?->staffMember?->is_active) {
                throw ValidationException::withMessages(['dependency' => 'Master Staff must be applied before staff assignments.']);
            }

            return $meta->staffMember;
        }
        $matches = StaffMember::query()->where('display_name', $item['display_name'])->lockForUpdate()->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['identity' => 'Staff identity became ambiguous during apply.']);
        }
        if ($matches->first()) {
            return $matches->first();
        }
        $created++;

        return StaffMember::create(['display_name' => $item['display_name'], 'is_active' => true]);
    }

    private function assertCapacity(Collection $plan): void
    {
        foreach (self::ROUTES as $route => $spec) {
            $students = $plan->firstWhere('route', $route)['bus_id'] ? StudentTransportAssignment::query()->where('bus_id', $plan->firstWhere('route', $route)['bus_id'])->where('status', 'active')->whereNull('effective_to')->count() : self::studentSourceCount($route);
            $staff = $plan->where('route', $route)->count();
            if ($students > 14 || $students + $staff > 15) {
                throw ValidationException::withMessages(['capacity' => "Physical occupancy exceeds 14/15 on {$route}."]);
            }
        }
    }

    private static function studentSourceCount(string $route): int
    {
        return ['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 12, 'Каусер' => 14, 'Эль Ахья' => 12][$route];
    }

    private function result(string $mode, Collection $plan): array
    {
        $capacity = collect(self::ROUTES)->map(function ($spec, $route) use ($plan) {
            $row = $plan->firstWhere('route', $route);
            $students = $row['bus_id'] ? StudentTransportAssignment::where('bus_id', $row['bus_id'])->where('status', 'active')->whereNull('effective_to')->count() : self::studentSourceCount($route);
            $staff = $plan->where('route', $route)->count();

            return ['route' => $route, 'students' => $students, 'peak_staff' => $staff, 'peak_physical_occupancy' => $students + $staff, 'passenger_capacity' => 15];
        })->values()->all();

        return ['mode' => $mode, 'expected_staff' => 11, 'proposed_staff_members' => $plan->where('identity_status', 'PROPOSED')->count(), 'identity_summary' => $plan->countBy('identity_status')->all(), 'new_assignments' => $plan->where('assignment_exists', false)->count(), 'existing_assignments' => $plan->where('assignment_exists', true)->count(), 'capacity_valid' => true, 'capacity' => $capacity, 'rows' => $plan->values()->all()];
    }

    private function sourceKey(array $row): string
    {
        return hash('sha256', json_encode([$row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name']], JSON_UNESCAPED_UNICODE));
    }

    private function sourceTrace(array $row, string $key): string
    {
        return 'Authoritative master staff transport: '.json_encode(['source_key' => $key, 'file' => $row['source_file'], 'sheet' => $row['source_sheet'], 'row' => (int) $row['source_row'], 'raw_identity' => $row['raw_full_name'], 'raw_pickup' => $row['raw_pickup_point'], 'raw_contact' => $row['raw_phone'], 'raw_notes' => $row['raw_notes']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function weekdays(string $value): ?array
    {
        preg_match('/\(([^)]*)\)/u', $value, $m);
        if (! isset($m[1])) {
            return null;
        } $days = collect(preg_split('/\s*,\s*/u', $m[1]))->map(fn ($d) => self::DAYS[$this->key($d)] ?? null)->filter()->unique()->sort()->values()->all();

        return $days ?: null;
    }

    private function key(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $this->canonical($value))));
    }

    private function canonical(string $value): string
    {
        return class_exists(Normalizer::class) ? Normalizer::normalize($value, Normalizer::FORM_C) : $value;
    }
}
