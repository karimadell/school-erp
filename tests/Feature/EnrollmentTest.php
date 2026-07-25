<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * enrollments.class_room_id was NOT NULL with no default and is not
     * fillable on the Enrollment model (a leftover from the superseded
     * ClassRoom concept — class_id is the column actually used). Every
     * enrollment store() call failed with a database error before this fix.
     * academic_year_id was also NOT NULL with no default despite the
     * controller treating it as optional, which failed the same way
     * whenever a request omitted it.
     */
    public function test_a_student_can_be_enrolled_without_an_academic_year(): void
    {
        $user = User::factory()->create();
        $stage = Stage::create(['name' => 'Elementary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::forceCreate(['code' => 'A', 'name_ar' => 'A', 'grade_id' => $grade->id]);
        $student = Student::forceCreate(['name' => 'Test Student']);

        $response = $this->actingAs($user)
            ->post(route('dashboard.enrollments.store', $student), [
                'stage_id' => $stage->id,
                'grade_id' => $grade->id,
                'class_id' => $class->id,
                'status' => 'active',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'is_active' => 1,
        ]);
    }
}
