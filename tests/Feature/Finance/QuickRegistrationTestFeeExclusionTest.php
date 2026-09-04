<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pre-Premium-UI corrective pass — Decision 2.
 *
 * fees.is_test_data must exclude a Fee from Quick Registration's
 * operational service-discovery list WITHOUT deleting, deactivating, or
 * otherwise mutating the Fee row — historical invoice_items referencing it
 * must remain completely readable, and every other active Fee must be
 * completely unaffected.
 */
class QuickRegistrationTestFeeExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected array $base;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Сидорова', 'student_first_name_ru' => 'Мария',
            'phone' => '+20 100 111 2233', 'registration_date' => '2026-08-15',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
        ];

        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);
    }

    private function transportFee(string $name): Fee
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '600.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '600.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);

        return $fee;
    }

    private function transportRoute(): int
    {
        return DB::table('transport_routes')->insertGetId(['name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_fees_default_to_not_test_data(): void
    {
        $fee = $this->transportFee('Транспорт');
        $this->assertFalse((bool) $fee->fresh()->is_test_data);
    }

    public function test_test_fee_is_excluded_from_quick_registration(): void
    {
        $operational = $this->transportFee('Транспорт');
        $test = $this->transportFee('UAT_FINANCE_PHASE2_TEST');
        $test->update(['is_test_data' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk();
        $response->assertSee('data-fee-id="'.$operational->id.'"', false);
        $response->assertDontSee('data-fee-id="'.$test->id.'"', false);
        $response->assertDontSee('UAT_FINANCE_PHASE2_TEST');
    }

    public function test_operational_fee_remains_visible_alongside_a_flagged_test_fee(): void
    {
        $operational = $this->transportFee('Транспорт');
        $test = $this->transportFee('UAT_FINANCE_PHASE2_TEST');
        $test->update(['is_test_data' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()->assertSee('Транспорт');
    }

    public function test_historical_invoice_reference_to_a_later_flagged_fee_remains_readable(): void
    {
        $fee = $this->transportFee('Транспорт');
        $route = $this->transportRoute();

        // Real invoice issued while the Fee was still ordinary (not yet
        // flagged) — mirrors the realistic sequence: historical data
        // exists first, the test-data flag is applied to it afterward.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $route, 'payment_period' => 'monthly']],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoiceId = Invoice::sole()->id;

        // Now flag it as test data, after the fact.
        $fee->update(['is_test_data' => true]);

        $invoice = Invoice::with('items')->findOrFail($invoiceId);
        $this->assertNotEmpty($invoice->items);
        $this->assertSame($fee->id, $invoice->items->first()->fee_id);
        $this->assertSame($fee->id, $invoice->items->first()->fee->id);
        $this->assertSame('Транспорт', $invoice->items->first()->fee->name_ru);
    }

    public function test_category_behavior_is_otherwise_unchanged_for_a_non_test_fee(): void
    {
        $fee = $this->transportFee('Транспорт');
        $route = $this->transportRoute();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $route, 'payment_period' => 'monthly']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(11, Invoice::sole()->installments()->count());
    }
}
