<?php

namespace App\Services\MasterData;

use App\Models\AuditLog;
use App\Models\StaffMasterImport;
use App\Models\StaffMember;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\TransportImportPreviewService;
use App\Support\RouteNameNormalizer;
use App\Support\TransportPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterStaffImportService
{
    private const DAYS = ['пн' => 1, 'вт' => 2, 'ср' => 3, 'чт' => 4, 'пт' => 5, 'сб' => 6, 'вс' => 7];

    public function __construct(private MasterDataWorkbookParser $parser, private TransportImportPreviewService $transport) {}

    public function defaultPath(): string
    {
        return storage_path('app/master-data/2026-27 СПИСОК СОТРУДНИКОВ ОЦ.xlsx');
    }

    public function preview(?string $path = null, ?array $transportPaths = null): array
    {
        return $this->result('PREVIEW', $this->plan($path ?? $this->defaultPath(), $transportPaths));
    }

    public function apply(User $actor, ?string $path = null, ?array $transportPaths = null): array
    {
        abort_unless($actor->isActive() && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        $plan = $this->preparePlan($path, $transportPaths);

        return DB::transaction(fn () => $this->persistPlan($actor, $plan));
    }

    public function preparePlan(?string $path = null, ?array $transportPaths = null): array
    {
        return $this->plan($path ?? $this->defaultPath(), $transportPaths);
    }

    /** Persist a previously validated plan. The caller owns the transaction. */
    public function persistPlan(User $actor, array $plan): array
    {
        abort_unless($actor->isActive() && $actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        $created = 0;
        $metadata = StaffMasterImport::query()->whereIn('source_key', $plan['staff']->pluck('source_key'))->lockForUpdate()->get()->keyBy('source_key');
        $locators = $this->importsByLocator(StaffMasterImport::query()->whereIn('source_file', $plan['staff']->pluck('source_file')->unique())->lockForUpdate()->get());
        foreach ($plan['staff'] as $item) {
            $locator = $locators->get($this->locatorKey($item['source_file'], $item['source_sheet'], $item['source_row']));
            if ($locator && $locator->source_key !== $item['source_key']) {
                throw ValidationException::withMessages(['source' => 'Staff master source row changed after import.']);
            }
            $meta = $metadata->get($item['source_key']);
            if ($meta) {
                $this->assertMetadata($meta, $item);

                continue;
            }
            $member = $item['staff_member_id'] ? StaffMember::lockForUpdate()->findOrFail($item['staff_member_id']) : StaffMember::create(['display_name' => $item['raw_name'], 'phone' => null, 'user_id' => null, 'is_active' => true]);
            if (! $item['staff_member_id']) {
                AuditLog::create(['user_id' => $actor->id, 'action' => 'master_staff_member_created', 'model' => StaffMember::class, 'model_id' => $member->id, 'old_values' => null, 'new_values' => $member->toArray()]);
                $created++;
            }
            StaffMasterImport::create(['source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'], 'source_row' => $item['source_row'], 'raw_name' => $item['raw_name'],
                'position' => $item['position'], 'raw_contact' => $item['raw_contact'], 'raw_birth_date' => $item['raw_birth_date'], 'source_data' => $this->staffSourceData($item), 'staff_member_id' => $member->id]);
        }

        return $this->result('APPLY', $plan) + ['created_staff_members' => $created, 'created_transport_links' => 0];
    }

    private function plan(string $path, ?array $transportPaths = null): array
    {
        $existingStaff = StaffMember::query()->get()->groupBy(fn ($member) => $this->key($member->display_name));
        $sourceRows = collect($this->parser->staff($path));
        $imports = StaffMasterImport::query()->whereIn('source_file', $sourceRows->pluck('source_file')->unique())->get();
        $importsByLocator = $this->importsByLocator($imports);
        $importsByKey = $imports->keyBy('source_key');
        $staff = $sourceRows->map(function ($r) use ($existingStaff, $importsByLocator, $importsByKey) {
            $sourceKey = $this->parser->sourceKey($r);
            $locator = $importsByLocator->get($this->locatorKey($r['source_file'], $r['source_sheet'], $r['source_row']));
            if ($locator && $locator->source_key !== $sourceKey) {
                throw ValidationException::withMessages(['source' => "Staff master source row {$r['source_sheet']}:{$r['source_row']} changed after import."]);
            }
            $matches = $existingStaff->get($this->key($r['raw_name']), collect());
            $item = $r + ['source_key' => $sourceKey, 'already_imported' => (bool) ($existing = $importsByKey->get($sourceKey)),
                'staff_member_id' => $matches->count() === 1 ? $matches->first()->id : null,
                'action' => $matches->isEmpty() ? 'CREATE_STAFF_MEMBER' : ($matches->count() === 1 ? 'LINK_STAFF_MEMBER' : 'REVIEW_REQUIRED'),
                'resolution_status' => $matches->isEmpty() ? 'NEW' : ($matches->count() === 1 ? 'DETERMINISTIC_MATCH' : 'AMBIGUOUS'),
                'candidate_staff_member_ids' => $matches->pluck('id')->sort()->values()->all()];
            if ($existing) {
                $this->assertMetadata($existing, $item);
            }

            return $item;
        });
        if ($staff->count() !== 26 || $staff->pluck('source_key')->unique()->count() !== 26) {
            throw ValidationException::withMessages(['source' => 'Expected 26 unique staff master rows.']);
        }
        if ($staff->where('action', 'REVIEW_REQUIRED')->isNotEmpty()) {
            throw ValidationException::withMessages(['identity' => 'Ambiguous existing StaffMember identity requires review.']);
        }
        $bySig = $staff->groupBy(fn ($r) => $this->fullSig($r['raw_name']));
        $links = collect();
        $paths = $transportPaths ?? (glob(storage_path('app/transport-import/*.xlsx')) ?: []);
        $catalog = $this->catalogPlan($paths);
        foreach ($paths as $p) {
            foreach ($this->transport->parseWorkbook($p) as $row) {
                if ($this->transport->classify($row) === 'STAFF') {
                    $display = trim(preg_replace('/\s*\([^)]*\)/u', '', $row['raw_full_name']));
                    $candidates = $bySig->get($this->abbrSig($display), collect());
                    if ($candidates->count() !== 1) {
                        throw ValidationException::withMessages(['identity' => "Staff initials are not unique for {$display}."]);
                    }
                    $master = $candidates->first();
                    $confirmedStalePhone = in_array($display, ['Лебедева Г.Г.', 'Чумакова В.В.', 'Щербакова О.В.'], true);
                    $sourceKey = hash('sha256', json_encode([$row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name']], JSON_UNESCAPED_UNICODE));
                    $catalogItem = collect($catalog)->first(fn ($item) => RouteNameNormalizer::key($item['canonical_name']) === RouteNameNormalizer::key($row['route']));
                    $links->push($row + ['display_name' => $display, 'source_key' => $sourceKey, 'master_source_key' => $master['source_key'], 'master_name' => $master['raw_name'], 'master_row' => $master['source_row'], 'position' => $master['position'],
                        'master_phone' => $master['raw_contact'], 'phone_evidence' => $confirmedStalePhone ? 'CONFIRMED_IDENTITY_STALE_PHONE_PRESERVED' : 'MATCH/NOT_REQUIRED', 'status' => 'CONFIRMED', 'weekdays' => $this->weekdays($row['raw_full_name'].' '.$row['raw_notes']),
                        'staff_member_id' => $master['staff_member_id'], 'route_id' => $catalogItem['route_id'], 'bus_id' => $catalogItem['bus_id'],
                        'assignment_status' => $catalogItem['assignment_ready'] ? 'READY' : 'PENDING_CATALOG_APPLY', 'proposed_role' => VehicleStaffAssignment::ROLE_STAFF_PASSENGER]);
                }
            }
        }
        if ($links->count() !== 11) {
            throw ValidationException::withMessages(['transport' => 'Expected 11 Transport staff rows.']);
        }

        return ['staff' => $staff, 'links' => $links, 'catalog' => collect($catalog)];
    }

    private function importsByLocator(Collection $imports): Collection
    {
        return $imports->groupBy(fn (StaffMasterImport $row) => $this->locatorKey($row->source_file, $row->source_sheet, $row->source_row))
            ->map(function (Collection $rows, string $locator): StaffMasterImport {
                if ($rows->count() !== 1) {
                    [$file, $sheet, $row] = explode("\0", $locator);
                    throw ValidationException::withMessages(['source' => "Duplicate staff master import locator: {$file} / {$sheet} / {$row}."]);
                }

                return $rows->first();
            });
    }

    private function locatorKey(string $file, string $sheet, int|string $row): string
    {
        return $file."\0".$sheet."\0".(int) $row;
    }

    private function result(string $mode, array $p): array
    {
        return ['mode' => $mode, 'source_rows' => 26, 'new_staff_members' => $p['staff']->where('action', 'CREATE_STAFF_MEMBER')->where('already_imported', false)->count(), 'existing_staff_members' => $p['staff']->where('action', 'LINK_STAFF_MEMBER')->count(), 'transport_links_confirmed' => $p['links']->where('status', 'CONFIRMED')->count(), 'transport_links_review_required' => 0, 'vehicle_staff_assignments_proposed' => 11, 'vehicle_staff_assignments_ready' => $p['links']->where('assignment_status', 'READY')->count(), 'vehicle_staff_assignments' => 0, 'transport_catalog' => $p['catalog']->values()->all(), 'staff' => $p['staff']->values()->all(), 'transport_links' => $p['links']->values()->all()];
    }

    private function catalogPlan(array $paths): array
    {
        $source = collect($paths)->flatMap(fn ($path) => $this->transport->parseWorkbook($path))
            ->groupBy(fn ($row) => RouteNameNormalizer::key($row['route']));
        $routes = TransportRoute::query()->with('buses')->get();

        return $source->map(function ($rows) use ($routes) {
            $name = RouteNameNormalizer::canonicalName($rows->first()['route']);
            $matches = $routes->filter(fn ($route) => RouteNameNormalizer::key($route->name) === RouteNameNormalizer::key($name))->values();
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['transport_catalog' => "Multiple existing routes match {$name}."]);
            }
            $route = $matches->first();
            $buses = $route?->buses ?? collect();
            if ($buses->count() > 1) {
                throw ValidationException::withMessages(['transport_catalog' => "Multiple existing buses match canonical route {$name}."]);
            }
            if ($buses->first() && ((int) $buses->first()->student_capacity !== 14 || (int) $buses->first()->passenger_capacity !== 15)) {
                throw ValidationException::withMessages(['capacity' => "Existing bus for {$name} is not the canonical 14-student / 15-passenger minibus."]);
            }
            $students = $rows->filter(fn ($row) => $this->transport->classify($row) === 'STUDENT')->count();
            $staff = $rows->filter(fn ($row) => $this->transport->classify($row) === 'STAFF')->count();
            if ($students > 14 || $students + $staff > 15) {
                throw ValidationException::withMessages(['capacity' => "Canonical route {$name} exceeds 14 students / 15 passengers."]);
            }

            $bus = $buses->first();

            return ['canonical_name' => $name, 'route_action' => $route ? ($route->is_active ? 'REUSE_ROUTE' : 'REACTIVATE_ROUTE') : 'CREATE_ROUTE', 'route_id' => $route?->id,
                'bus_action' => $bus ? ($bus->is_active ? 'REUSE_BUS' : 'REACTIVATE_BUS') : 'CREATE_BUS', 'bus_id' => $bus?->id,
                'assignment_ready' => (bool) ($route?->is_active && $bus?->is_active),
                'student_capacity' => 14, 'passenger_capacity' => 15, 'source_students' => $students, 'source_staff' => $staff];
        })->sortBy('canonical_name')->values()->all();
    }

    private function fullSig(string $v): string
    {
        $p = preg_split('/\s+/u', $this->key($v), -1, PREG_SPLIT_NO_EMPTY);

        return count($p) === 1 ? $p[0] : $p[0].' '.mb_substr($p[1], 0, 1).(isset($p[2]) ? mb_substr($p[2], 0, 1) : '');
    }

    private function abbrSig(string $v): string
    {
        $p = preg_split('/[^\pL]+/u', $this->key($v), -1, PREG_SPLIT_NO_EMPTY);

        return count($p) === 1 ? $p[0] : $p[0].' '.implode('', array_slice($p, 1));
    }

    private function key(string $v): string
    {
        return mb_strtolower(str_replace('ё', 'е', trim($v)));
    }

    private function weekdays(string $v): ?array
    {
        preg_match('/\(([^)]*)\)/u', $v, $m);
        if (! isset($m[1])) {
            return null;
        }$d = collect(preg_split('/\s*,\s*/u', $m[1]))->map(fn ($x) => self::DAYS[$this->key($x)] ?? null)->filter()->unique()->sort()->values()->all();

        return $d ?: null;
    }

    private function assertMetadata(StaffMasterImport $m, array $i): void
    {
        if ($m->source_file !== $i['source_file'] || $m->source_sheet !== $i['source_sheet'] || (int) $m->source_row !== $i['source_row'] || $m->raw_name !== $i['raw_name']
            || $m->position !== $i['position'] || $m->raw_contact !== $i['raw_contact'] || $m->raw_birth_date !== $i['raw_birth_date'] || $m->source_data != $this->staffSourceData($i)
            || ! $m->staff_member_id || ! $m->staffMember) {
            throw ValidationException::withMessages(['source' => 'Staff master metadata conflict.']);
        }
    }

    private function staffSourceData(array $item): array
    {
        return collect($item)->only(['source_file', 'source_sheet', 'source_row', 'raw_name', 'raw_birth_date', 'position', 'raw_contact'])->all();
    }
}
