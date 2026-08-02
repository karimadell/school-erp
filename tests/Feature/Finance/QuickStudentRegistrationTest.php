<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuickStudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private AcademicYear $year;
    private Stage $stage;
    private Grade $grade;
    private EnrollmentMode $mode;
    private CashAccount $account;
    private Fee $registrationFee;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа']);
        $this->grade = Grade::create(['name' => '1 класс', 'stage_id' => $this->stage->id]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        $this->account = CashAccount::create(['name' => 'Касса', 'type' => 'cash']);
        $this->registrationFee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION, '1000.00');
    }

    private function fee(string $name, string $category, string $amount): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => $amount, 'is_active' => true]);
    }

    private function payload(array $services, array $overrides = []): array
    {
        return array_replace([
            'student_name_ru' => 'Иван Иванов', 'phone' => '01012345678',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id,
            'grade_id' => $this->grade->id, 'enrollment_mode_id' => $this->mode->id,
            'registration_date' => '2026-08-02', 'services' => $services,
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ], $overrides);
    }

    private function service(Fee $fee, string $paid = '0.00', array $metadata = []): array
    {
        return array_merge(['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => $paid], $metadata);
    }

    public function test_provisional_student_enrollment_invoice_and_summary_are_created(): void
    {
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee),
        ]));

        $student = Student::sole();
        $invoice = Invoice::sole();
        $response->assertRedirect(route('dashboard.quick-registration.summary', $invoice));
        $this->assertSame(Student::STATUS_PRE_REGISTERED, $student->status);
        $this->assertTrue($student->has_incomplete_profile);
        $this->assertSame('2026-08-02', Enrollment::sole()->enrollment_date->toDateString());
        $this->assertSame('1000.00', $invoice->total_amount);
        $this->get(route('dashboard.quick-registration.summary', $invoice))->assertOk()
            ->assertSee('Личное дело не завершено')->assertSee('Продолжить оформление личного дела');
    }

    public function test_registration_fee_can_only_appear_once_per_year(): void
    {
        $second = $this->fee('Ещё один регистрационный взнос', Fee::CATEGORY_REGISTRATION, '500.00');
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee), $this->service($second),
        ]))->assertSessionHasErrors('services');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_partial_registration_payment_posts_one_atomic_payment(): void
    {
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee, '250.00'),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Invoice::STATUS_PARTIAL, Invoice::sole()->status);
        $this->assertSame('250.00', InvoiceItem::sole()->paid_amount);
        $this->assertSame('750.00', InvoiceItem::sole()->remaining_amount);
        $this->assertSame('250.00', InvoicePayment::sole()->amount);
        $this->assertSame('250.00', CashTransaction::sole()->amount);
    }

    public function test_full_registration_payment_marks_invoice_paid(): void
    {
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee, '1000.00'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::STATUS_PAID, Invoice::sole()->status);
        $this->assertSame('0.00', Invoice::sole()->remaining_amount);
    }

    public function test_multiple_services_use_server_prices_and_canonical_items(): void
    {
        $books = $this->fee('Книги', Fee::CATEGORY_BOOKS, '300.00');
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee, '100.00'), $this->service($books, '50.00'),
        ], ['total_amount' => '0.01']))->assertSessionHasErrors('total_amount');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee, '100.00'), $this->service($books, '50.00'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('1300.00', Invoice::sole()->total_amount);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseCount('student_service_subscriptions', 2);
        $this->assertSame(2, Invoice::sole()->fees()->count());
    }

    public function test_uniform_quantity_and_metadata_are_snapshotted(): void
    {
        $uniform = $this->fee('Футболка', Fee::CATEGORY_UNIFORM, '200.00');
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($uniform, '100.00', ['quantity' => 2, 'item' => 'Футболка', 'size' => 'M']),
        ]))->assertSessionHasNoErrors();
        $item = InvoiceItem::sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('200.00', $item->unit_price);
        $this->assertSame('400.00', $item->amount);
        $this->assertSame(['item' => 'Футболка', 'size' => 'M'], $item->metadata);
    }

    public function test_transport_metadata_is_preserved_on_item_and_subscription(): void
    {
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT, '500.00');
        $metadata = ['transport_area' => 'Мубарак 6', 'transport_route' => 'Маршрут 2', 'transport_stop' => 'Школа'];
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($transport, '0.00', $metadata),
        ]))->assertSessionHasNoErrors();
        $expected = ['area' => 'Мубарак 6', 'route' => 'Маршрут 2', 'stop' => 'Школа'];
        $this->assertSame($expected, InvoiceItem::sole()->metadata);
        $this->assertSame($expected, StudentServiceSubscription::sole()->metadata);
    }

    public function test_everything_rolls_back_when_calculation_fails(): void
    {
        $this->app->instance(InvoiceCalculationService::class, new class extends InvoiceCalculationService {
            public function calculate(array $items, ?string $discountType = null, string|int|float|null $discountValue = null, string|int|float|null $initialPaymentAmount = null, ?string $pricingDate = null): array
            {
                throw ValidationException::withMessages(['services' => 'Ошибка расчёта.']);
            }
        });
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->service($this->registrationFee),
        ]))->assertSessionHasErrors('services');
        foreach (['students', 'enrollments', 'invoices', 'invoice_items', 'student_service_subscriptions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_validation_messages_are_russian(): void
    {
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), []);
        $response->assertSessionHasErrors(['student_name_ru', 'phone', 'services']);
        $this->assertSame('Укажите имя ученика на русском языке.', session('errors')->first('student_name_ru'));
    }

    public function test_teacher_reception_and_no_role_are_denied(): void
    {
        foreach (['teacher', null] as $role) {
            $user = User::factory()->create(['is_active' => true]);
            if ($role) {
                $user->assignRole($role);
            }
            $this->actingAs($user)->get(route('dashboard.quick-registration.create'))->assertRedirect('/login');
            $this->actingAs($user)->post(route('dashboard.quick-registration.store'), [])->assertRedirect('/login');
        }

        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $this->actingAs($reception)->get(route('dashboard.quick-registration.create'))->assertForbidden();
        $this->actingAs($reception)->post(route('dashboard.quick-registration.store'), [])->assertForbidden();
    }
}
