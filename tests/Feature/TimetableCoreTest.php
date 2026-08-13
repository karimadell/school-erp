<?php

namespace Tests\Feature;

use App\Filament\Resources\TimetableVersions\TimetableVersionResource;
use App\Models\Academic\Timetable as AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\PhysicalClassroom;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\TimetableVersionService;
use App\Support\AcademicYearLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TimetableCoreTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $this->publisher = User::factory()->create();
    }

    public function test_draft_can_be_created_and_edited(): void
    {
        $version = $this->draft();
        $this->assertSame(TimetableVersion::STATUS_DRAFT, $version->status);

        $version->update(['name' => 'Revised draft']);

        $this->assertSame('Revised draft', $version->fresh()->name);
        $this->assertNull($version->fresh()->published_at);
    }

    public function test_publish_records_actor_and_active_lookup(): void
    {
        $version = app(TimetableVersionService::class)->publish($this->draft(), $this->publisher);

        $this->assertSame(TimetableVersion::STATUS_PUBLISHED, $version->status);
        $this->assertTrue($version->publisher->is($this->publisher));
        $this->assertNotNull($version->published_at);
        $this->assertTrue(app(TimetableVersionService::class)
            ->activeVersionFor($this->year, CarbonImmutable::parse('2026-10-01'))
            ?->is($version));
        $this->assertNull(app(TimetableVersionService::class)
            ->activeVersionFor($this->year, CarbonImmutable::parse('2027-07-01')));
    }

    public function test_publishing_new_version_archives_previous_version(): void
    {
        $first = app(TimetableVersionService::class)->publish($this->draft(['name' => 'First']), $this->publisher);
        $second = app(TimetableVersionService::class)->publish($this->draft([
            'name' => 'Second', 'effective_from' => '2027-01-01',
        ]), $this->publisher);

        $this->assertSame(TimetableVersion::STATUS_ARCHIVED, $first->fresh()->status);
        $this->assertNull($first->fresh()->published_slot);
        $this->assertSame(TimetableVersion::STATUS_PUBLISHED, $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->published_slot);
        $this->assertNull(app(TimetableVersionService::class)
            ->activeVersionFor($this->year, CarbonImmutable::parse('2026-10-01')));
        $this->assertTrue(app(TimetableVersionService::class)
            ->activeVersionFor($this->year, CarbonImmutable::parse('2027-02-01'))
            ?->is($second));
    }

    public function test_database_enforces_one_published_version_per_academic_year(): void
    {
        app(TimetableVersionService::class)->publish($this->draft(), $this->publisher);

        $this->expectException(QueryException::class);
        DB::table('timetable_versions')->insert([
            'academic_year_id' => $this->year->id, 'name' => 'Unsafe duplicate',
            'status' => 'published', 'effective_from' => '2026-09-01', 'effective_to' => '2027-06-30',
            'published_by' => $this->publisher->id, 'published_at' => now(), 'published_slot' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_draft_can_be_published(): void
    {
        $draft = $this->draft();

        try {
            $draft->update(['status' => TimetableVersion::STATUS_PUBLISHED]);
            $this->fail('Expected direct publication to be rejected.');
        } catch (ValidationException) {
            $this->assertSame(TimetableVersion::STATUS_DRAFT, $draft->fresh()->status);
        }

        $draft->refresh();
        $published = app(TimetableVersionService::class)->publish($draft, $this->publisher);

        $this->expectException(ValidationException::class);
        app(TimetableVersionService::class)->publish($published, $this->publisher);
    }

    public function test_effective_date_range_is_validated(): void
    {
        foreach ([
            ['effective_from' => '2027-01-01', 'effective_to' => '2026-12-31'],
            ['effective_from' => '2026-08-31', 'effective_to' => '2027-06-30'],
            ['effective_from' => '2026-09-01', 'effective_to' => '2027-07-01'],
        ] as $invalidDates) {
            try {
                $this->draft($invalidDates);
                $this->fail('Expected effective-date validation.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('timetable_versions', $invalidDates);
            }
        }
    }

    public function test_published_version_is_immutable_and_hard_delete_is_forbidden(): void
    {
        $version = app(TimetableVersionService::class)->publish($this->draft(), $this->publisher);

        foreach ([
            fn () => $version->update(['name' => 'Changed']),
            fn () => $version->delete(),
        ] as $write) {
            try {
                $write();
                $this->fail('Expected historical protection.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('timetable_versions', ['id' => $version->id, 'name' => 'Main timetable']);
            }
        }
    }

    public function test_published_header_and_entries_cannot_be_mutated_or_reassigned(): void
    {
        $version = $this->draft();
        $header = $this->header($version);
        [$schedule, $period, $classroom] = $this->entryReferences($this->year);
        $entry = TimetableEntry::create($this->entryAttributes($version, $header, $schedule, $period, $classroom));
        app(TimetableVersionService::class)->publish($version, $this->publisher);

        $otherVersion = $this->draft(['name' => 'Other draft']);
        $otherHeader = $this->header($otherVersion, 'Other header');

        foreach ([
            fn () => AcademicTimetable::findOrFail($header->id)->update(['name' => 'Changed']),
            fn () => AcademicTimetable::findOrFail($header->id)->update(['timetable_version_id' => $otherVersion->id]),
            fn () => AcademicTimetable::findOrFail($header->id)->delete(),
            fn () => TimetableEntry::findOrFail($entry->id)->update(['weekday' => 2]),
            fn () => TimetableEntry::findOrFail($entry->id)->update([
                'timetable_version_id' => $otherVersion->id,
                'academic_timetable_id' => $otherHeader->id,
            ]),
            fn () => TimetableEntry::findOrFail($entry->id)->delete(),
            fn () => TimetableEntry::create($this->entryAttributes($version, $header, $schedule, $period, $classroom, ['weekday' => 2])),
        ] as $write) {
            try {
                $write();
                $this->fail('Expected published graph immutability.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('academic_timetables', [
                    'id' => $header->id, 'timetable_version_id' => $version->id, 'name' => 'Primary school',
                ]);
                $this->assertDatabaseHas('timetable_entries', [
                    'id' => $entry->id, 'timetable_version_id' => $version->id, 'weekday' => 1,
                ]);
            }
        }
    }

    public function test_entry_rejects_cross_year_and_mismatched_structural_references(): void
    {
        $version = $this->draft();
        $header = $this->header($version);
        [$schedule, $period, $classroom] = $this->entryReferences($this->year);

        $otherYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01',
            'end_date' => '2026-06-30', 'is_active' => false,
        ]);
        [$otherSchedule, $otherPeriod, $otherClassroom] = AcademicYearLock::withoutLock(
            fn () => $this->entryReferences($otherYear, 'OTHER'),
        );
        $otherVersion = $this->draft(['name' => 'Same-year alternate']);
        $otherHeader = $this->header($otherVersion, 'Alternate header');
        [$secondSchedule, $secondPeriod] = $this->entryReferences($this->year, 'SECOND');

        foreach ([
            $this->entryAttributes($version, $header, $otherSchedule, $otherPeriod, $classroom),
            $this->entryAttributes($version, $header, $schedule, $period, $otherClassroom),
            $this->entryAttributes($version, $otherHeader, $schedule, $period, $classroom),
            $this->entryAttributes($version, $header, $schedule, $secondPeriod, $classroom),
        ] as $invalidEntry) {
            try {
                TimetableEntry::create($invalidEntry);
                $this->fail('Expected timetable reference validation.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('timetable_entries', $invalidEntry);
            }
        }

        $this->assertNotSame($schedule->id, $secondSchedule->id);
    }

    public function test_academic_year_delete_is_restricted_while_version_exists(): void
    {
        $this->draft();

        $this->expectException(QueryException::class);
        $this->year->delete();
    }

    public function test_new_header_is_isolated_from_legacy_timetable_model(): void
    {
        $version = $this->draft();
        $header = AcademicTimetable::create([
            'timetable_version_id' => $version->id, 'name' => 'Primary school',
        ]);

        $this->assertSame('academic_timetables', $header->getTable());
        $this->assertSame('timetables', (new Timetable)->getTable());
        $this->assertTrue($header->version->is($version));
    }

    public function test_resource_reuses_timetable_permissions_and_protects_published_versions(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'view timetable']);
        Permission::create(['name' => 'manage timetable']);
        $this->actingAs($user);

        $this->assertFalse(TimetableVersionResource::canViewAny());
        $user->givePermissionTo('view timetable');
        $this->assertTrue(TimetableVersionResource::canViewAny());
        $this->assertFalse(TimetableVersionResource::canCreate());
        $user->givePermissionTo('manage timetable');
        $this->assertTrue(TimetableVersionResource::canCreate());

        $draft = $this->draft();
        $this->assertTrue(TimetableVersionResource::canEdit($draft));
        $published = app(TimetableVersionService::class)->publish($draft, $this->publisher);
        $this->assertFalse(TimetableVersionResource::canEdit($published));
        $this->assertFalse(TimetableVersionResource::canDelete($published));
    }

    private function draft(array $overrides = []): TimetableVersion
    {
        return TimetableVersion::create(array_merge([
            'academic_year_id' => $this->year->id,
            'name' => 'Main timetable',
            'effective_from' => '2026-09-01',
            'effective_to' => '2027-06-30',
            'notes' => 'Foundation version',
        ], $overrides));
    }

    private function header(TimetableVersion $version, string $name = 'Primary school'): AcademicTimetable
    {
        return AcademicTimetable::create(['timetable_version_id' => $version->id, 'name' => $name]);
    }

    private function entryReferences(AcademicYear $year, string $suffix = 'MAIN'): array
    {
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => "Schedule {$suffix}",
            'shift' => 1, 'is_default' => false, 'is_active' => true,
        ]);
        $period = BellSchedulePeriod::create([
            'bell_schedule_id' => $schedule->id, 'period_number' => 1,
            'starts_at' => '08:00', 'ends_at' => '08:45', 'is_active' => true,
        ]);
        $classroom = PhysicalClassroom::create([
            'academic_year_id' => $year->id, 'building' => 'Main', 'floor' => '1',
            'code' => "ROOM-{$suffix}", 'name' => "Room {$suffix}", 'capacity' => 30,
            'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true,
        ]);

        return [$schedule, $period, $classroom];
    }

    private function entryAttributes(
        TimetableVersion $version,
        AcademicTimetable $header,
        BellSchedule $schedule,
        BellSchedulePeriod $period,
        PhysicalClassroom $classroom,
        array $overrides = [],
    ): array {
        return array_merge([
            'timetable_version_id' => $version->id,
            'academic_timetable_id' => $header->id,
            'weekday' => 1,
            'bell_schedule_id' => $schedule->id,
            'bell_schedule_period_id' => $period->id,
            'classroom_id' => $classroom->id,
        ], $overrides);
    }
}
