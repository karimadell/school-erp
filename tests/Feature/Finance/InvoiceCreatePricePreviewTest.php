<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause regression coverage for the reported "invoice did not save"
 * UAT symptom: dashboard/invoices/create.blade.php used to build its JS
 * price-preview payload from a fee's *entire, unfiltered* prices
 * relation — no is_active / academic_year / date-range filtering, no
 * ordering — while the server's authoritative
 * InvoiceCalculationService::resolvePrice() applies all of those
 * constraints. Whenever a fee carried more than one FeePrice row (e.g.
 * a stale prior-year tuition price left on the same Fee), the employee
 * could be shown a total the server would never accept; paying "the
 * full amount as displayed" then failed outright as a false
 * overpayment, with zero records created and no on-screen explanation.
 */
class InvoiceCreatePricePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_screen_excludes_stale_prior_year_price_from_its_preview_payload(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $stage = Stage::create(['name' => 'Начальная школа']);
        $grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => '1A', 'name_ar' => '1A']);
        $student = Student::create(['name' => 'Иван Иванов']);

        $oldYear = AcademicYear::create([
            'name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false,
        ]);
        $year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'status' => 'active', 'is_active' => true,
        ]);
        $account = CashAccount::operating();
        app(\App\Services\Finance\CashSessionService::class)->open($account, $user);

        $fee = Fee::create(['name_ru' => 'Обучение', 'amount' => '1803.00', 'is_active' => true, 'category' => 'tuition']);

        // Last year's (higher, stale) tuition price for the same fee —
        // inactive academic year, but still a row under $fee->prices().
        FeePrice::create([
            'fee_id' => $fee->id, 'amount' => '1900.00', 'currency' => 'EGP',
            'academic_year_id' => $oldYear->id, 'is_active' => true,
            'start_date' => '2025-08-01', 'end_date' => '2026-06-30',
        ]);
        // This year's real, authoritative price.
        FeePrice::create([
            'fee_id' => $fee->id, 'amount' => '1803.00', 'currency' => 'EGP',
            'academic_year_id' => $year->id, 'is_active' => true,
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.invoices.create'));
        $response->assertOk();

        $priceRows = $response->viewData('priceRows');
        $amountsForFee = array_values(array_map(
            fn (array $row) => $row['amount'],
            array_filter($priceRows, fn (array $row) => $row['fee_id'] === $fee->id),
        ));

        $this->assertSame([1803.0], $amountsForFee, 'The create screen must only preview the current, active-year price.');

        // With the preview now matching the server, paying "the full
        // amount as displayed" (1803.00) succeeds instead of being
        // rejected as a false overpayment.
        $store = $this->actingAs($user)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'due_date' => '2026-09-01',
            'fees' => [$fee->id],
            'cash_account_id' => $account->id,
            'payment_method' => 'cash',
            'initial_payment_amount' => '1803.00',
        ]);

        $store->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('1803.00', $invoice->paid_amount);
    }
}
