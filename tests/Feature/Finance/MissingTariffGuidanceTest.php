<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance Pricing Admin UX corrective, Scope E: the already-established
 * generic "На выбранную дату тариф не настроен." fail-loud case (unchanged
 * — see InvoiceCalculationService, untouched by this PR) is now enriched,
 * for exactly-one-item submissions only, with operator-facing context and
 * — for users with 'manage fee prices' only — a link into the existing
 * FeePrice admin screen. Never blocks, never infers a price, never fires
 * for the mode/period ambiguity guards, which already name their own
 * missing dimension.
 */
class MissingTariffGuidanceTest extends FinanceOperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCanonicalRegistrationModeCatalog();
    }

    private function missingPriceItem(): array
    {
        // $this->fee only has a price for $this->enrollment->grade_id
        // (FinanceOperationsTestCase's own fixture) — requesting a
        // DIFFERENT grade_group with zero configured rows reproduces the
        // exact generic "no tariff at all" case, not the mode/period
        // ambiguity guards.
        return ['fee_id' => $this->fee->id, 'grade_group' => 'Подготовительный класс', 'payment_period' => 'yearly'];
    }

    // 13. Unified Collection: no writes, clear message, authorized link
    // with correct trusted context.
    public function test_unified_collection_missing_tariff_shows_message_and_link_for_authorized_user(): void
    {
        $before = [
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
        ];

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            [
                'idempotency_token' => (string) Str::uuid(),
                'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
                'new_services' => [array_merge($this->missingPriceItem(), ['quantity' => 1, 'receive_now_amount' => '100.00'])],
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertSame($before, ['invoices' => Invoice::count(), 'invoice_items' => DB::table('invoice_items')->count()]);
        $this->assertNotNull(session('missing_tariff_message'));
        $this->assertStringContainsString('Цена не настроена', session('missing_tariff_message'));
        $link = session('missing_tariff_link');
        $this->assertNotNull($link);
        $this->assertStringContainsString('/admin/fee-prices/create', $link);
        $this->assertStringContainsString('fee_id='.$this->fee->id, $link);
        $this->assertStringContainsString('academic_year_id='.$this->year->id, $link);
        $this->assertStringContainsString('enrollment_mode=full_time', $link);
    }

    // 14. Same scenario, an authorized-for-invoices-but-not-fee-prices
    // user: message present, NO link.
    public function test_unified_collection_missing_tariff_shows_no_link_for_unauthorized_price_manager(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $response = $this->actingAs($cashier)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            [
                'idempotency_token' => (string) Str::uuid(),
                'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
                'new_services' => [array_merge($this->missingPriceItem(), ['quantity' => 1, 'receive_now_amount' => '100.00'])],
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertNotNull(session('missing_tariff_message'));
        $this->assertNull(session('missing_tariff_link'));
    }

    // 15. Classic Invoice (StudentInvoiceController) — equivalent behavior.
    public function test_classic_invoice_missing_tariff_shows_message_and_link_for_authorized_user(): void
    {
        $before = ['invoices' => Invoice::count(), 'invoice_items' => DB::table('invoice_items')->count()];

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'due_date' => '2027-06-30',
                'pricing_date' => '2026-09-10', 'idempotency_key' => (string) Str::uuid(),
                'fees' => [$this->fee->id], 'grade_group' => [$this->fee->id => 'Подготовительный класс'],
                'payment_period' => [$this->fee->id => 'yearly'], 'initial_payment_amount' => '0',
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertSame($before, ['invoices' => Invoice::count(), 'invoice_items' => DB::table('invoice_items')->count()]);
        $this->assertNotNull(session('missing_tariff_message'));
        $this->assertNotNull(session('missing_tariff_link'));
    }

    public function test_classic_invoice_missing_tariff_shows_no_link_for_unauthorized_price_manager(): void
    {
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $response = $this->actingAs($cashier)->post(
            route('dashboard.students.invoices.store', $this->student),
            [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'due_date' => '2027-06-30',
                'pricing_date' => '2026-09-10', 'idempotency_key' => (string) Str::uuid(),
                'fees' => [$this->fee->id], 'grade_group' => [$this->fee->id => 'Подготовительный класс'],
                'payment_period' => [$this->fee->id => 'yearly'], 'initial_payment_amount' => '0',
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertNotNull(session('missing_tariff_message'));
        $this->assertNull(session('missing_tariff_link'));
    }

    // 16. Quick Registration — equivalent behavior through its own,
    // materially-similar architecture (a new explicit catch mirroring the
    // exact redirect shape Laravel's own default ValidationException
    // handling already produced for this controller).
    public function test_quick_registration_missing_tariff_shows_message_and_link_for_authorized_user(): void
    {
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Подготовительный', 'stage_id' => $stage->id, 'level' => 0]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'П-А', 'name_ru' => 'П-А', 'name_ar' => 'P-A', 'is_active' => true]);
        $mode = EnrollmentMode::where('code', 'full_time')->firstOrFail();

        $before = Invoice::count();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), [
            'student_last_name_ru' => 'Петров', 'student_first_name_ru' => 'Пётр', 'phone' => '+201099998888',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'enrollment_mode_id' => $mode->id, 'registration_date' => '2026-09-01',
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'grade_group' => 'Подготовительный класс', 'payment_period' => 'yearly']],
            'cash_account_id' => $this->cash->id, 'payment_method' => 'cash',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, Invoice::count());
        $this->assertNotNull(session('missing_tariff_message'));
        $link = session('missing_tariff_link');
        $this->assertNotNull($link);
        $this->assertStringContainsString('enrollment_mode=full_time', $link);
    }

    public function test_quick_registration_missing_tariff_shows_no_link_for_unauthorized_price_manager(): void
    {
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Подготовительный', 'stage_id' => $stage->id, 'level' => 0]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'П-А', 'name_ru' => 'П-А', 'name_ar' => 'P-A', 'is_active' => true]);
        $mode = EnrollmentMode::where('code', 'full_time')->firstOrFail();
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $response = $this->actingAs($cashier)->post(route('dashboard.quick-registration.store'), [
            'student_last_name_ru' => 'Сидоров', 'student_first_name_ru' => 'Сидор', 'phone' => '+201099998877',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'enrollment_mode_id' => $mode->id, 'registration_date' => '2026-09-01',
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'grade_group' => 'Подготовительный класс', 'payment_period' => 'yearly']],
            'cash_account_id' => $this->cash->id, 'payment_method' => 'cash',
        ]);

        $response->assertSessionHasErrors();
        $this->assertNotNull(session('missing_tariff_message'));
        $this->assertNull(session('missing_tariff_link'));
    }

    // 17. External missing-price regression: external mode + a grade
    // group with no configured external price must fail loudly, never
    // borrowing full_time/family/no_enrollment/generic/another-grade
    // pricing.
    public function test_external_missing_price_never_borrows_another_mode_or_grade(): void
    {
        $external = EnrollmentMode::where('code', EnrollmentMode::EXTERNAL)->sole();
        $this->enrollment->update(['enrollment_mode_id' => $external->id]);

        // Real external price exists ONLY for a DIFFERENT grade group.
        FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_group' => '1–4 классы',
            'payment_period' => 'yearly', 'option_type' => 'enrollment_mode', 'option_value' => 'external',
            'amount' => '25600.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        // A full_time price also exists for the target (Preparatory) group.
        FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_group' => 'Подготовительный класс',
            'payment_period' => 'yearly', 'option_type' => 'enrollment_mode', 'option_value' => 'full_time',
            'amount' => '33300.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $before = ['invoices' => Invoice::count(), 'invoice_items' => DB::table('invoice_items')->count()];

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'due_date' => '2027-06-30',
                'pricing_date' => '2026-09-10', 'idempotency_key' => (string) Str::uuid(),
                'fees' => [$this->fee->id], 'grade_group' => [$this->fee->id => 'Подготовительный класс'],
                'payment_period' => [$this->fee->id => 'yearly'], 'initial_payment_amount' => '0',
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertSame($before, ['invoices' => Invoice::count(), 'invoice_items' => DB::table('invoice_items')->count()]);
    }
}
