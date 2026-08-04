<?php

namespace Tests\Feature\Finance;

use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Validation\ValidationException;

class DateBasedTariffSelectionTest extends QuickRegistrationUxTestCase
{
    public function test_early_regular_midyear_and_late_dates_select_the_containing_version(): void
    {
        [$year, , $grade, , $mode] = $this->structure();
        $year->update(['start_date' => '2026-09-01']);
        $grade->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        $mode->update(['code' => EnrollmentMode::FULL_TIME]);
        $fee = $this->tuitionWithVersions($year->id);
        $service = app(InvoiceCalculationService::class);
        $item = ['fee_id'=>$fee->id, 'quantity'=>1, 'grade_id'=>$grade->id,
            'payment_period'=>'yearly', 'enrollment_mode_id'=>$mode->id];

        foreach ([
            '2026-05-15'=>'30000.00', '2026-07-31'=>'30000.00',
            '2026-08-01'=>'40500.00', '2026-09-15'=>'40500.00',
            '2027-01-15'=>'40500.00', '2027-06-29'=>'40500.00',
        ] as $date => $expected) {
            $this->assertSame($expected, $service->calculate([$item], pricingDate:$date, academicYearId:$year->id)['total_amount']);
        }
    }

    public function test_missing_interval_is_russian_validation_and_creates_nothing(): void
    {
        [$year, , $grade, , $mode] = $this->structure();
        $year->update(['start_date'=>'2026-09-01']);
        $grade->forceFill(['level'=>4])->save();
        $fee = $this->tuitionWithVersions($year->id);

        try {
            app(InvoiceCalculationService::class)->calculate([['fee_id'=>$fee->id,'grade_id'=>$grade->id,
                'payment_period'=>'yearly','enrollment_mode_id'=>$mode->id]], pricingDate:'2026-04-30', academicYearId:$year->id);
            $this->fail('Ожидалась ошибка отсутствующего тарифа.');
        } catch (ValidationException $exception) {
            $this->assertSame('На выбранную дату тариф не настроен.', $exception->errors()['fees'][0]);
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_quick_preview_and_final_invoice_use_the_same_early_registration_date(): void
    {
        $structure = $this->structure();
        [$year, , $grade, , $mode] = $structure;
        $year->update(['start_date'=>'2026-09-01']);
        $grade->forceFill(['name'=>'4 КЛАСС','level'=>4])->save();
        $fee = $this->tuitionWithVersions($year->id);
        $selection = ['fee_id'=>$fee->id,'quantity'=>1,'paid_now'=>'0.00','payment_period'=>'yearly'];

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id'=>$fee->id,'quantity'=>1,'academic_year_id'=>$year->id,'grade_id'=>$grade->id,
            'enrollment_mode_id'=>$mode->id,'payment_period'=>'yearly','registration_date'=>'2026-05-15',
        ])->assertOk()->assertJson(['unit_price'=>'30000.00','valid_from'=>'2026-05-01','valid_to'=>'2026-07-31']);

        $this->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'registration_date'=>'2026-05-15','services'=>[$selection],
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('30000.00', Invoice::sole()->total_amount);
        $this->assertSame('30000.00', Invoice::sole()->items()->sole()->unit_price);
    }

    public function test_modern_invoice_creation_uses_selected_issue_date_and_preserves_snapshot(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $year->update(['start_date'=>'2026-09-01']);
        $grade->forceFill(['name'=>'4 КЛАСС','level'=>4])->save();
        $fee = $this->tuitionWithVersions($year->id);
        $student = Student::create(['last_name_ru'=>'Петров','first_name_ru'=>'Пётр','phone'=>'+201011111111','class_id'=>$class->id]);
        Enrollment::create(['student_id'=>$student->id,'academic_year_id'=>$year->id,'enrollment_mode_id'=>$mode->id,
            'stage_id'=>$stage->id,'grade_id'=>$grade->id,'class_id'=>$class->id,'academic_year'=>$year->name,
            'enrollment_date'=>'2026-05-15','enrolled_at'=>'2026-05-15','status'=>'active','is_active'=>true]);

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store',$student), [
            'student_id'=>$student->id,'academic_year_id'=>$year->id,'pricing_date'=>'2026-05-15',
            'due_date'=>'2027-06-30','fees'=>[$fee->id], 'payment_period'=>[$fee->id=>'yearly'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('30000.00', $invoice->total_amount);
        $this->assertSame('30000.00', $invoice->items()->sole()->unit_price);
        $this->assertSame('2026-05-15', $invoice->created_at->toDateString());
        FeePrice::where('amount','30000.00')->update(['is_active'=>false]);
        $this->assertSame('30000.00', $invoice->fresh()->items()->sole()->unit_price);
    }

    public function test_overlapping_early_tariff_is_rejected_but_non_overlapping_versions_are_allowed(): void
    {
        [$year] = $this->structure();
        $year->update(['start_date'=>'2026-09-01']);
        $fee = $this->tuitionWithVersions($year->id);
        $payload = ['fee_id'=>$fee->id,'academic_year_id'=>$year->id,'amount'=>'29000.00','currency'=>'EGP',
            'start_date'=>'2026-07-15','end_date'=>'2026-08-15','is_active'=>'1','grade_group'=>'1–4 классы','payment_period'=>'yearly'];
        $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'),$payload)
            ->assertSessionHasErrors('start_date');
        $this->assertSame(2, $fee->prices()->count());
    }

    private function tuitionWithVersions(int $yearId): Fee
    {
        $fee = Fee::create(['name_ru'=>'Обучение','category'=>Fee::CATEGORY_TUITION,'type'=>'service','amount'=>'0.00','is_active'=>true]);
        foreach ([['30000.00','2026-05-01','2026-07-31','Льготный тариф при ранней оплате.'],['40500.00','2026-08-01','2027-06-30','Основной тариф на учебный год.']] as [$amount,$start,$end,$reason]) {
            FeePrice::create(['fee_id'=>$fee->id,'academic_year_id'=>$yearId,'grade_group'=>'1–4 классы','payment_period'=>'yearly',
                'amount'=>$amount,'currency'=>'EGP','start_date'=>$start,'end_date'=>$end,'change_reason'=>$reason,'is_active'=>true]);
        }
        return $fee;
    }
}
