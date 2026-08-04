<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class FinanceOperationsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;
    protected Student $student;
    protected AcademicYear $year;
    protected Enrollment $enrollment;
    protected Fee $fee;
    protected CashAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active'=>true]);
        $this->accountant->assignRole('accountant');
        $this->year = AcademicYear::create(['name'=>'2026/2027','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        $stage = Stage::create(['name'=>'Начальная школа','order'=>1,'is_active'=>true]);
        $grade = Grade::forceCreate(['name'=>'1 КЛАСС','stage_id'=>$stage->id,'level'=>1]);
        $class = SchoolClass::create(['grade_id'=>$grade->id,'code'=>'А','name_ru'=>'1-А','name_ar'=>'1-А','is_active'=>true]);
        $mode = EnrollmentMode::create(['code'=>'full_time','name_ru'=>'Очная','is_active'=>true]);
        $this->student = Student::create(['last_name_ru'=>'Иванов','first_name_ru'=>'Иван','patronymic_ru'=>'Иванович','phone'=>'+201001112233','class_id'=>$class->id,'status'=>'registration_completed']);
        $this->enrollment = Enrollment::create(['student_id'=>$this->student->id,'academic_year_id'=>$this->year->id,'enrollment_mode_id'=>$mode->id,'stage_id'=>$stage->id,'grade_id'=>$grade->id,'class_id'=>$class->id,'academic_year'=>$this->year->name,'enrollment_date'=>'2026-08-01','enrolled_at'=>'2026-08-01','status'=>'active','is_active'=>true]);
        $this->fee = Fee::create(['name_ru'=>'Обучение','category'=>'tuition','amount'=>'1.00','is_active'=>true]);
        FeePrice::create(['fee_id'=>$this->fee->id,'academic_year_id'=>$this->year->id,'grade_id'=>$grade->id,'payment_period'=>'yearly','amount'=>'1200.00','currency'=>'EGP','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        $this->cash = CashAccount::create(['name'=>'Основная касса','type'=>'cash','balance'=>'0.00','is_active'=>true]);
    }

    protected function invoice(string $total='1200.00', ?string $dueDate=null): Invoice
    {
        $invoice = Invoice::create(['student_id'=>$this->student->id,'academic_year_id'=>$this->year->id,'customer_name'=>$this->student->full_name,'currency'=>'EGP','subtotal_amount'=>$total,'total_amount'=>$total,'discount_amount'=>'0.00','paid_amount'=>'0.00','remaining_amount'=>$total,'status'=>'unpaid','due_date'=>$dueDate ?? '2027-01-01','created_by'=>$this->accountant->id]);
        $invoice->invoice_number=Invoice::numberFor($invoice->id,'2026'); $invoice->save();
        InvoiceItem::create(['invoice_id'=>$invoice->id,'fee_id'=>$this->fee->id,'description'=>'Обучение','unit_price'=>$total,'quantity'=>1,'amount'=>$total,'paid_amount'=>'0.00','remaining_amount'=>$total]);
        return $invoice;
    }

    protected function user(string $role, bool $active=true): User
    {
        $user=User::factory()->create(['is_active'=>$active]); $user->assignRole($role); return $user;
    }
}
