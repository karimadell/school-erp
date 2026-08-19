<?php

namespace Tests\Feature;

use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\PhysicalClassroom;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    public function test_classroom_can_be_created_with_all_supported_fields(): void
    {
        $room = app(ClassroomService::class)->create($this->attributes());

        $this->assertTrue($room->academicYear->is($this->year));
        $this->assertSame('Main', $room->building);
        $this->assertSame('2', $room->floor);
        $this->assertSame(30, $room->capacity);
        $this->assertTrue($room->is_active);
    }

    public function test_physical_classroom_and_legacy_class_room_resolve_without_ambiguity(): void
    {
        $this->assertTrue(class_exists(PhysicalClassroom::class));
        $this->assertTrue(class_exists(ClassRoom::class));
        $this->assertNotSame(PhysicalClassroom::class, ClassRoom::class);
        $this->assertSame(PhysicalClassroom::class, ClassroomResource::getModel());

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringNotContainsString('PhysicalClassroom', ClassroomResource::getModelLabel());
        }
    }

    public function test_every_supported_room_type_is_accepted(): void
    {
        foreach (PhysicalClassroom::TYPES as $index => $type) {
            $room = PhysicalClassroom::create($this->attributes([
                'code' => 'R-'.$index,
                'room_type' => $type,
            ]));
            $this->assertSame($type, $room->room_type);
        }
    }

    public function test_capacity_must_be_at_least_one(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalClassroom::create($this->attributes(['capacity' => 0]));
    }

    public function test_room_type_must_be_supported(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalClassroom::create($this->attributes(['room_type' => 'warehouse']));
    }

    public function test_code_is_unique_within_academic_year_and_database_enforces_it(): void
    {
        PhysicalClassroom::create($this->attributes());

        try {
            PhysicalClassroom::create($this->attributes(['name' => 'Duplicate']));
            $this->fail('Expected domain duplicate validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->expectException(QueryException::class);
        DB::table('classrooms')->insert(array_merge($this->attributes(['name' => 'Direct duplicate']), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_same_code_is_allowed_in_a_different_academic_year(): void
    {
        PhysicalClassroom::create($this->attributes());
        $otherYear = AcademicYear::create([
            'name' => '2027 / 2028', 'start_date' => '2027-09-01',
            'end_date' => '2028-06-30', 'is_active' => true,
        ]);

        $room = PhysicalClassroom::create($this->attributes(['academic_year_id' => $otherYear->id]));
        $this->assertSame('A-201', $room->code);
    }

    public function test_available_rooms_exclude_inactive_rooms_for_future_assignment(): void
    {
        $active = PhysicalClassroom::create($this->attributes());
        $inactive = PhysicalClassroom::create($this->attributes(['code' => 'A-202', 'is_active' => false]));

        $available = app(ClassroomService::class)->availableFor($this->year);

        $this->assertTrue($available->contains($active));
        $this->assertFalse($available->contains($inactive));
        $this->assertDatabaseHas('classrooms', ['id' => $inactive->id, 'is_active' => false]);
    }

    public function test_hard_delete_is_forbidden_even_for_an_active_year(): void
    {
        $room = PhysicalClassroom::create($this->attributes());

        try {
            $room->delete();
            $this->fail('Expected hard-delete validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('classroom.validation.hard_delete_forbidden'),
                $exception->errors()['classroom'][0],
            );
            $this->assertDatabaseHas('classrooms', ['id' => $room->id]);
        }
    }

    /**
     * Pre-UAT fix (independent review, Fix 9): previously reused the shared
     * setUp() $this->year, hardcoded to 2026-09-01..2027-06-30.
     * AcademicYear::isHistorical() requires BOTH !is_active AND
     * end_date->isPast(), so whether this test's expectation held depended
     * on the wall-clock date the suite happened to run on. It now builds
     * its own self-contained year with an end_date computed relative to
     * now(), so it stays genuinely historical no matter when the suite
     * executes. $this->year from setUp() is untouched and unused here, so
     * no other test in this class is affected.
     */
    public function test_historical_room_is_preserved_and_locked(): void
    {
        $historicalYear = AcademicYear::create([
            'name' => 'Historical ' . uniqid(),
            'start_date' => now()->subYears(2)->startOfYear()->toDateString(),
            'end_date' => now()->subYears(2)->endOfYear()->toDateString(),
            'is_active' => true,
        ]);
        $room = PhysicalClassroom::create($this->attributes(['academic_year_id' => $historicalYear->id]));

        // Activating a later year atomically deactivates $historicalYear
        // (AcademicYear::save()'s single-active-year swap) — its end_date
        // is already in the past, so it is now genuinely historical.
        AcademicYear::create([
            'name' => 'Current ' . uniqid(),
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);

        foreach ([
            fn () => $room->update(['name' => 'Changed']),
            fn () => $room->delete(),
        ] as $historicalWrite) {
            try {
                $historicalWrite();
                $this->fail('Expected historical lock validation.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('classrooms', ['id' => $room->id, 'name' => 'Room 201']);
            }
        }

        try {
            $historicalYear->delete();
            $this->fail('Expected restricted historical FK.');
        } catch (QueryException) {
            $this->assertDatabaseHas('classrooms', ['id' => $room->id]);
        }
    }

    public function test_resource_reuses_admin_only_timetable_permissions_and_disables_delete(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'view timetable']);
        Permission::create(['name' => 'manage timetable']);
        $this->actingAs($user);
        $this->assertFalse(ClassroomResource::canViewAny());
        $user->givePermissionTo('view timetable');
        $this->assertTrue(ClassroomResource::canViewAny());
        $this->assertFalse(ClassroomResource::canCreate());
        $user->givePermissionTo('manage timetable');
        $this->assertTrue(ClassroomResource::canCreate());
        $this->assertFalse(ClassroomResource::canDelete(PhysicalClassroom::create($this->attributes())));
    }

    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $this->year->id,
            'building' => 'Main', 'floor' => '2', 'code' => 'A-201', 'name' => 'Room 201',
            'capacity' => 30, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM,
            'is_active' => true, 'notes' => 'Near the library',
        ], $overrides);
    }
}
