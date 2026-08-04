<?php

namespace Tests\Feature\Academic;

use App\Models\Grade;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GradeNumericOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_level_column_is_the_canonical_numeric_order(): void
    {
        $stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);

        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '10 класс', 'level' => 10]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '2 класс', 'level' => 2]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => 'Класс без номера', 'level' => null]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '0 класс', 'level' => 0]);

        $this->assertTrue(Schema::hasColumn('grades', 'level'));
        $this->assertSame(
            ['0 класс', '2 класс', '10 класс', 'Класс без номера'],
            Grade::ordered()->pluck('name')->all(),
        );
        $this->assertSame(
            ['0 класс', '2 класс', '10 класс', 'Класс без номера'],
            $stage->grades()->pluck('name')->all(),
        );
    }

    public function test_level_is_cast_to_integer(): void
    {
        $stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['stage_id' => $stage->id, 'name' => '11 класс', 'level' => '11']);

        $this->assertSame(11, $grade->level);
        $this->assertSame(11, $grade->fresh()->level);
    }
}
