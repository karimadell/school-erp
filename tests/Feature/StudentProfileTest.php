<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    public function test_modern_student_profile_renders_all_six_russian_tabs(): void
    {
        [$student] = $this->profileData();

        $this->actingAs($this->user('admin'))->get(route('dashboard.students.show', $student))
            ->assertOk()
            ->assertSee('Профиль ученика')
            ->assertSee('Обзор')
            ->assertSee('Родители')
            ->assertSee('Финансы')
            ->assertSee('Услуги')
            ->assertSee('Документы')
            ->assertSee('История')
            ->assertSee('Иванов Иван Иванович')
            ->assertSee('Ivan Ivanov')
            ->assertSee('إيفان إيفانوف')
            ->assertSee('Иванов Сергей')
            ->assertSee('Редактировать')
            ->assertSee('Перевести')
            ->assertSee('Печать');
    }

    public function test_manage_students_permission_protects_profile(): void
    {
        [$student] = $this->profileData();

        $this->actingAs($this->user('accountant'))
            ->get(route('dashboard.students.show', $student))->assertForbidden();
        $this->actingAs($this->user('reception'))
            ->get(route('dashboard.students.show', $student))->assertOk();
    }

    public function test_financial_summary_is_read_only_and_exact(): void
    {
        [$student, $invoice] = $this->profileData();

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.students.show', $student));
        $response->assertOk()
            ->assertSee('3 000.00 EGP')
            ->assertSee('1 000.00 EGP')
            ->assertSee('2 000.00 EGP')
            ->assertSee('Есть задолженность')
            ->assertSee($invoice->display_number)
            ->assertSee('Предстоящие платежи');

        $this->assertSame('3000.00', $invoice->fresh()->total_amount);
        $this->assertSame('1000.00', $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('invoice_payments', 1);
    }

    public function test_timeline_is_newest_first(): void
    {
        [$student] = $this->profileData();

        $this->actingAs($this->user('admin'))->get(route('dashboard.students.show', $student))
            ->assertOk()
            ->assertViewHas('timeline', function ($timeline) {
                $dates = $timeline->pluck('at')->map->timestamp->values();

                return $dates->all() === $dates->sortDesc()->values()->all()
                    && $timeline->first()['type'] === 'Платёж';
            });
    }

    /** @return array{Student, Invoice} */
    private function profileData(): array
    {
        Carbon::setTestNow('2026-08-04 09:00:00');
        $year = AcademicYear::create(['name'=>'2026/2027','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        $stage = Stage::create(['name'=>'Начальная школа','order'=>1,'is_active'=>true]);
        $grade = Grade::forceCreate(['name'=>'1 класс','stage_id'=>$stage->id,'level'=>1]);
        $class = SchoolClass::create(['grade_id'=>$grade->id,'code'=>'А','name_ru'=>'1-А','name_ar'=>'A','is_active'=>true]);
        $student = Student::create([
            'name'=>'Иванов Иван Иванович','class_id'=>$class->id,'status'=>'active','nationality'=>'Россия',
            'documents'=>[
                'name_en'=>'Ivan Ivanov','name_ar'=>'إيفان إيفانوف','identity_document'=>'Свидетельство 123',
                'father'=>['name'=>'Иванов Сергей','phone'=>'+20 100 000 0000','email'=>'father@example.test'],
                'mother'=>['name'=>'Иванова Анна','phone'=>'+20 111 111 1111'],
                'emergency_contact'=>'+20 122 222 2222',
            ],
        ]);
        $enrollment = Enrollment::create([
            'student_id'=>$student->id,'academic_year_id'=>$year->id,'stage_id'=>$stage->id,'grade_id'=>$grade->id,
            'class_id'=>$class->id,'academic_year'=>$year->name,'enrollment_date'=>'2026-08-01','enrolled_at'=>'2026-08-01',
            'status'=>'active','is_active'=>true,
        ]);
        $fee = Fee::create(['name_ru'=>'Обучение','category'=>Fee::CATEGORY_TUITION,'amount'=>'1.00','is_active'=>true]);
        FeePrice::create(['fee_id'=>$fee->id,'academic_year_id'=>$year->id,'grade_id'=>$grade->id,'payment_period'=>'yearly','amount'=>'3000.00','currency'=>'EGP','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        StudentServiceSubscription::create(['enrollment_id'=>$enrollment->id,'fee_id'=>$fee->id,'start_date'=>'2026-08-01','status'=>'active','metadata'=>['payment_period'=>'yearly']]);

        Carbon::setTestNow('2026-08-05 10:00:00');
        $invoice = Invoice::create([
            'student_id'=>$student->id,'academic_year_id'=>$year->id,'customer_name'=>$student->name,'currency'=>'EGP',
            'subtotal_amount'=>'3000.00','total_amount'=>'3000.00','discount_amount'=>'0.00','paid_amount'=>'1000.00',
            'remaining_amount'=>'2000.00','status'=>Invoice::STATUS_PARTIAL,'due_date'=>'2026-09-01','created_by'=>null,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        $cash = CashAccount::create(['name'=>'Основная касса','type'=>'cash','balance'=>'1000.00']);
        Carbon::setTestNow('2026-08-06 11:00:00');
        InvoicePayment::create(['invoice_id'=>$invoice->id,'cash_account_id'=>$cash->id,'amount'=>'1000.00','payment_method'=>'cash','paid_at'=>now(),'reference'=>'PROFILE-TEST']);
        Carbon::setTestNow();

        return [$student, $invoice];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active'=>true]);
        $user->assignRole($role);

        return $user;
    }
}
