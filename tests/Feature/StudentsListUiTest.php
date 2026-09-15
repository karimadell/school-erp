<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Students List UI corrective — presentation only. StudentController::index()
 * still builds the exact same query it always did (search, gender filter,
 * $student->class eager load, paginate(10)), with one small, safe addition
 * (a class_id filter on the same Student.class_id relation the table's own
 * "Класс" column already reads — never the Enrollment-based "current
 * academic year" class) and three plain COUNT queries for the summary
 * cards. No Student schema, no new routes, no permission changes.
 */
class StudentsListUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    protected function makeClass(string $code = 'A'): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => $code,
            'name_ar' => 'فصل '.$code,
            'name_ru' => 'Класс '.$code,
            'capacity' => 25,
            'is_active' => true,
        ]);
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ----- 1/2. Page loads and shows real rows -----------------------------

    public function test_students_page_loads_and_renders_real_student_rows(): void
    {
        $class = $this->makeClass();
        $student = Student::create([
            'last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'class_id' => $class->id,
            'gender' => 'male', 'phone' => '+201001112233', 'nationality' => 'Egypt',
        ]);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));

        $response->assertOk();
        $response->assertSee($student->full_name);
        $response->assertSee('+201001112233');
        $response->assertSee('Egypt');
    }

    // ----- 3. Search still works ---------------------------------------------

    public function test_search_by_name_still_filters_correctly(): void
    {
        $match = Student::create(['last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр']);
        $other = Student::create(['last_name_ru' => 'Сидоров', 'first_name_ru' => 'Семён']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index', ['q' => 'Петров']));

        $response->assertOk();
        $response->assertSee($match->full_name);
        $response->assertDontSee($other->full_name);
    }

    // ----- 4. Existing gender filter still works ------------------------------

    public function test_gender_filter_still_works(): void
    {
        $male = Student::create(['last_name_ru' => 'Мужской', 'first_name_ru' => 'Тест', 'gender' => 'male']);
        $female = Student::create(['last_name_ru' => 'Женская', 'first_name_ru' => 'Тест', 'gender' => 'female']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index', ['gender' => 'male']));

        $response->assertOk();
        $response->assertSee($male->full_name);
        $response->assertDontSee($female->full_name);
    }

    // ----- 5/6. Class display uses the existing Student.class relation -------

    public function test_class_badge_shows_the_existing_student_class_relation(): void
    {
        $class = $this->makeClass('B');
        $student = Student::create(['last_name_ru' => 'Классный', 'first_name_ru' => 'Ученик', 'class_id' => $class->id]);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));

        $response->assertOk();
        $response->assertSee($class->name);
    }

    public function test_student_with_no_class_renders_safely(): void
    {
        $student = Student::forceCreate(['name' => 'Без класса']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));

        $response->assertOk();
        $response->assertSee($student->full_name);
    }

    public function test_class_filter_uses_the_same_student_class_id_relation(): void
    {
        $classA = $this->makeClass('A');
        $classB = $this->makeClass('B');
        $inA = Student::create(['last_name_ru' => 'ВКлассеА', 'first_name_ru' => 'Ученик', 'class_id' => $classA->id]);
        $inB = Student::create(['last_name_ru' => 'ВКлассеБ', 'first_name_ru' => 'Ученик', 'class_id' => $classB->id]);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index', ['class_id' => $classA->id]));

        $response->assertOk();
        $response->assertSee($inA->full_name);
        $response->assertDontSee($inB->full_name);
    }

    // ----- 7. Open action points to the existing route ------------------------

    public function test_open_action_points_to_the_existing_show_route(): void
    {
        $student = Student::create(['last_name_ru' => 'Открыть', 'first_name_ru' => 'Тест']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));

        $response->assertOk();
        $response->assertSee(route('dashboard.students.show', $student), false);
    }

    // ----- 8/11. Edit follows existing authorization --------------------------

    public function test_edit_action_still_follows_existing_authorization(): void
    {
        $student = Student::create(['last_name_ru' => 'Тест', 'first_name_ru' => 'Тест']);

        // Cashier has 'view students' (reaches the index) but not 'update
        // students' — confirms the redesign grants no new capability: the
        // link is unconditionally present (unchanged from before this
        // pass), but the route itself still enforces the real permission.
        $cashier = $this->user('cashier');
        $this->assertFalse($cashier->can('update students'));

        $this->actingAs($cashier)->get(route('dashboard.students.index'))->assertOk();
        $this->actingAs($cashier)->get(route('dashboard.students.edit', $student))->assertForbidden();
    }

    // ----- 9/10. Add Enrollment remains available and targets the SAME Student --

    public function test_add_enrollment_action_is_present_and_targets_the_same_student(): void
    {
        $student = Student::create(['last_name_ru' => 'Зачисление', 'first_name_ru' => 'Тест']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));
        $response->assertOk();
        $response->assertSee(route('dashboard.enrollments.create', $student), false);

        $reception = $this->user('reception');
        $enrollResponse = $this->actingAs($reception)->get(route('dashboard.enrollments.create', $student));
        $enrollResponse->assertOk();
        $enrollResponse->assertViewHas('student', fn (Student $viewStudent) => $viewStudent->is($student));
    }

    // ----- 12/13. Summary cards use real, unfiltered data ---------------------

    public function test_summary_cards_use_real_unfiltered_data(): void
    {
        Student::create(['last_name_ru' => 'А', 'first_name_ru' => 'А', 'gender' => 'male']);
        Student::create(['last_name_ru' => 'Б', 'first_name_ru' => 'Б', 'gender' => 'male']);
        Student::create(['last_name_ru' => 'В', 'first_name_ru' => 'В', 'gender' => 'female']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index', ['q' => 'нет-совпадения']));

        $response->assertOk();
        $response->assertViewHas('studentSummary', ['total' => 3, 'male' => 2, 'female' => 1]);
    }

    // ----- 14. No fake status/active concept introduced ------------------------

    public function test_no_fake_active_status_card_is_introduced(): void
    {
        Student::create(['last_name_ru' => 'Тест', 'first_name_ru' => 'Тест']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index'));

        $response->assertOk();
        $response->assertDontSee('Активные');
    }

    // ----- 15. Pagination preserves filters -----------------------------------

    public function test_pagination_preserves_active_filters(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Student::create(['last_name_ru' => 'Фильтр'.$i, 'first_name_ru' => 'Тест', 'gender' => 'male']);
        }
        Student::create(['last_name_ru' => 'Другой', 'first_name_ru' => 'Пол', 'gender' => 'female']);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.index', ['gender' => 'male']));

        $response->assertOk();
        $response->assertSee('gender=male', false);
    }
}
