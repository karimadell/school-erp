<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Quarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): Quarter gains
 * academic_year_id, connecting terms to a specific academic year
 * (Section 2: "terms configurable per academic year"). Additive,
 * nullable — no existing consumer is required to change.
 */
class QuarterTest extends TestCase
{
    use RefreshDatabase;

    protected function makeYear(string $name = '2026 / 2027'): AcademicYear
    {
        return AcademicYear::create([
            'name' => $name,
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => false,
        ]);
    }

    public function test_quarter_can_be_created_with_an_academic_year(): void
    {
        $year = $this->makeYear();

        $quarter = Quarter::create([
            'academic_year_id' => $year->id,
            'name' => 'Q1',
            'order' => 1,
        ]);

        $this->assertDatabaseHas('quarters', [
            'id' => $quarter->id,
            'academic_year_id' => $year->id,
        ]);
    }

    public function test_quarter_academic_year_relationship_resolves(): void
    {
        $year = $this->makeYear();

        $quarter = Quarter::create([
            'academic_year_id' => $year->id,
            'name' => 'Q1',
            'order' => 1,
        ]);

        $this->assertTrue($quarter->academicYear->is($year));
    }

    public function test_academic_year_quarters_relationship_resolves(): void
    {
        $year = $this->makeYear();

        $q1 = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q1', 'order' => 1]);
        $q2 = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q2', 'order' => 2]);

        $this->assertCount(2, $year->quarters);
        $this->assertTrue($year->quarters->contains($q1));
        $this->assertTrue($year->quarters->contains($q2));
    }

    public function test_quarter_can_still_be_created_without_an_academic_year(): void
    {
        // Nullable — no regression for any existing caller that doesn't
        // supply academic_year_id (e.g. StudentGradesUniqueIndexTest's
        // fixtures, or any pre-B1 data).
        $quarter = Quarter::create(['name' => 'Q1', 'order' => 1]);

        $this->assertDatabaseHas('quarters', [
            'id' => $quarter->id,
            'academic_year_id' => null,
        ]);
    }

    public function test_deleting_an_academic_year_nulls_its_quarters_instead_of_deleting_them(): void
    {
        $year = $this->makeYear();

        $quarter = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q1', 'order' => 1]);

        $year->delete();

        $this->assertDatabaseHas('quarters', [
            'id' => $quarter->id,
            'academic_year_id' => null,
        ]);
    }
}
