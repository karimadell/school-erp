<?php

namespace Tests\Feature\Students;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class StudentCompletionTestCase extends TestCase
{
    use RefreshDatabase;
    protected User $manager;
    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp(); (new RolesAndPermissionsSeeder)->run();
        $this->manager=User::factory()->create(['is_active'=>true]); $this->manager->assignRole('admin');
        $year=AcademicYear::create(['name'=>'2026/2027','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        $stage=Stage::create(['name'=>'Начальная школа']); $grade=Grade::create(['name'=>'1 класс','stage_id'=>$stage->id]);
        $class=SchoolClass::create(['grade_id'=>$grade->id,'code'=>'1-А','name_ru'=>'1-А','name_ar'=>'1-A','is_active'=>true]);
        $mode=EnrollmentMode::create(['code'=>'regular','name_ru'=>'Очная','is_active'=>true]);
        $this->student=Student::create(['last_name_ru'=>'Иванов','first_name_ru'=>'Иван','phone'=>'+201000000000','status'=>Student::STATUS_PRE_REGISTERED,'class_id'=>$class->id]);
        Enrollment::create(['student_id'=>$this->student->id,'academic_year_id'=>$year->id,'stage_id'=>$stage->id,'grade_id'=>$grade->id,'class_id'=>$class->id,'enrollment_mode_id'=>$mode->id,'academic_year'=>$year->name,'enrollment_date'=>'2026-08-04','enrolled_at'=>'2026-08-04','status'=>'active','is_active'=>true]);
    }

    protected function profilePayload(array $overrides=[]): array
    {
        return array_replace(['last_name_ru'=>'Иванов','first_name_ru'=>'Иван','patronymic_ru'=>null,'gender'=>'male','birth_date'=>'2015-01-01','nationality'=>'Россия','address'=>'Хургада','phone'=>'+201000000000','father_phone'=>'+201011111111'], $overrides);
    }
}
