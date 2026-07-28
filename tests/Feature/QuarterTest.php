<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Quarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): Quarter gains
 * academic_year_id, connecting terms to a specific academic year
 * (Section 2: "terms configurable per academic year"). Originally added
 * nullable as a stopgap; Item 3 makes it required — see the tests below
 * for the coverage that flipped as a result.
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

    public function test_quarter_can_no_longer_be_created_without_an_academic_year(): void
    {
        // Item 3: academic_year_id became required. Reverses the guarantee
        // the old test_quarter_can_still_be_created_without_an_academic_year
        // asserted — that test's premise no longer holds.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Quarter::create(['name' => 'Q1', 'order' => 1]);
    }

    public function test_deleting_an_academic_year_with_quarters_is_restricted(): void
    {
        // Item 3: the FK's delete action changed from set-null to restrict
        // — a NOT NULL column can no longer be nulled out by a parent
        // delete, so deleting a year that still has quarters is now
        // blocked instead of silently orphaning them.
        $year = $this->makeYear();
        $quarter = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q1', 'order' => 1]);

        try {
            $year->delete();
            $this->fail('Expected a QueryException when deleting a year with quarters still attached.');
        } catch (\Illuminate\Database\QueryException $e) {
            // expected
        }

        $this->assertDatabaseHas('quarters', ['id' => $quarter->id, 'academic_year_id' => $year->id]);
        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
    }
}
