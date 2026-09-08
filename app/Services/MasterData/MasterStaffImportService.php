<?php

namespace App\Services\MasterData;

use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\StaffMasterImport;
use App\Models\StaffMember;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\TransportImportPreviewService;
use App\Support\TransportPermissions;
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

        return DB::transaction(function () use ($actor, $path, $transportPaths) {
            $plan = $this->plan($path ?? $this->defaultPath(), $transportPaths);
            $created = 0;
            foreach ($plan['staff'] as $item) {
                $locator = StaffMasterImport::where('source_file', $item['source_file'])->where('source_sheet', $item['source_sheet'])->where('source_row', $item['source_row'])->lockForUpdate()->first();
                if ($locator && $locator->source_key !== $item['source_key']) {
                    throw ValidationException::withMessages(['source' => 'Staff master source row changed after import.']);
                }
                $meta = StaffMasterImport::where('source_key', $item['source_key'])->lockForUpdate()->first();
                if ($meta) {
                    $this->assertMetadata($meta, $item);

                    continue;
                }
                $member = StaffMember::create(['display_name' => $item['raw_name'], 'phone' => null, 'user_id' => null, 'is_active' => true]);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'master_staff_member_created', 'model' => StaffMember::class, 'model_id' => $member->id, 'old_values' => null, 'new_values' => $member->toArray()]);
                StaffMasterImport::create(['source_key' => $item['source_key'], 'source_file' => $item['source_file'], 'source_sheet' => $item['source_sheet'], 'source_row' => $item['source_row'], 'raw_name' => $item['raw_name'],
                    'position' => $item['position'], 'raw_contact' => $item['raw_contact'], 'raw_birth_date' => $item['raw_birth_date'], 'source_data' => collect($item)->except(['source_key', 'already_imported'])->all(), 'staff_member_id' => $member->id]);
                $created++;
            }

            return $this->result('APPLY', $this->plan($path ?? $this->defaultPath(), $transportPaths)) + ['created_staff_members' => $created, 'created_transport_links' => 0];
        });
    }

    private function plan(string $path, ?array $transportPaths = null): array
    {
        $staff = collect($this->parser->staff($path))->map(function ($r) {
            $sourceKey = $this->parser->sourceKey($r);
            $locator = StaffMasterImport::where('source_file', $r['source_file'])->where('source_sheet', $r['source_sheet'])->where('source_row', $r['source_row'])->first();
            if ($locator && $locator->source_key !== $sourceKey) {
                throw ValidationException::withMessages(['source' => "Staff master source row {$r['source_sheet']}:{$r['source_row']} changed after import."]);
            }
            $item = $r + ['source_key' => $sourceKey, 'already_imported' => (bool) ($existing = StaffMasterImport::where('source_key', $sourceKey)->first())];
            if ($existing) {
                $this->assertMetadata($existing, $item);
            }

            return $item;
        });
        if ($staff->count() !== 26 || $staff->pluck('source_key')->unique()->count() !== 26) {
            throw ValidationException::withMessages(['source' => 'Expected 26 unique staff master rows.']);
        }
        $bySig = $staff->groupBy(fn ($r) => $this->fullSig($r['raw_name']));
        $links = collect();
        $paths = $transportPaths ?? (glob(storage_path('app/transport-import/*.xlsx')) ?: []);
        foreach ($paths as $p) {
            foreach ($this->transport->parseWorkbook($p) as $row) {
                if ($this->transport->classify($row) === 'STAFF') {
                    $display = trim(preg_replace('/\s*\([^)]*\)/u', '', $row['raw_full_name']));
                    $candidates = $bySig->get($this->abbrSig($display), collect());
                    if ($candidates->count() !== 1) {
                        throw ValidationException::withMessages(['identity' => "Staff initials are not unique for {$display}."]);
                    }
                    $master = $candidates->first();
                    $masterImport = StaffMasterImport::where('source_key', $master['source_key'])->first();
                    $confirmedStalePhone = in_array($display, ['Лебедева Г.Г.', 'Чумакова В.В.', 'Щербакова О.В.'], true);
                    $sourceKey = hash('sha256', json_encode([$row['source_file'], $row['source_sheet'], $row['source_row'], $row['raw_full_name']], JSON_UNESCAPED_UNICODE));
                    $route = TransportRoute::all()->first(fn ($item) => $this->canonical($item->name) === $this->canonical($row['route']));
                    if (! $route || ! ($bus = Bus::where('transport_route_id', $route->id)->first())) {
                        throw ValidationException::withMessages(['transport' => "No canonical bus for {$row['route']}."]);
                    }
                    $links->push($row + ['display_name' => $display, 'source_key' => $sourceKey, 'master_source_key' => $master['source_key'], 'staff_member_id' => $masterImport?->staff_member_id, 'master_name' => $master['raw_name'], 'master_row' => $master['source_row'], 'position' => $master['position'],
                        'master_phone' => $master['raw_contact'], 'phone_evidence' => $confirmedStalePhone ? 'CONFIRMED_IDENTITY_STALE_PHONE_PRESERVED' : 'MATCH/NOT_REQUIRED', 'status' => 'CONFIRMED', 'weekdays' => $this->weekdays($row['raw_full_name'].' '.$row['raw_notes']),
                        'bus_id' => $bus->id, 'proposed_role' => VehicleStaffAssignment::ROLE_STAFF_PASSENGER]);
                }
            }
        }
        if ($links->count() !== 11) {
            throw ValidationException::withMessages(['transport' => 'Expected 11 Transport staff rows.']);
        }

        return ['staff' => $staff, 'links' => $links];
    }

    private function result(string $mode, array $p): array
    {
        return ['mode' => $mode, 'source_rows' => 26, 'new_staff_members' => $p['staff']->where('already_imported', false)->count(), 'transport_links_confirmed' => $p['links']->where('status', 'CONFIRMED')->count(), 'transport_links_review_required' => 0, 'vehicle_staff_assignments_proposed' => 11, 'vehicle_staff_assignments' => 0, 'staff' => $p['staff']->values()->all(), 'transport_links' => $p['links']->values()->all()];
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

    private function canonical(string $v): string
    {
        return class_exists(\Normalizer::class) ? \Normalizer::normalize($v, \Normalizer::FORM_C) : $v;
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
            || $m->position !== $i['position'] || $m->raw_contact !== $i['raw_contact'] || $m->raw_birth_date !== $i['raw_birth_date'] || $m->source_data != collect($i)->except(['source_key', 'already_imported'])->all()
            || ! $m->staff_member_id || ! $m->staffMember) {
            throw ValidationException::withMessages(['source' => 'Staff master metadata conflict.']);
        }
    }
}
