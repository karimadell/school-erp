<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Support\DeterministicIdempotencyKey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PR 2 of the frozen Finance corrective plan — the legacy /invoices/create
 * screen (InvoiceController::store, route dashboard.invoices.store) must be
 * idempotent per rendered form: a double submit replays the same Invoice and
 * the same optional initial InvoicePayment instead of writing them twice.
 * The Classic Student Invoice route has its own coverage
 * (ClassicInvoiceIdempotencyTest) and is unchanged by this PR.
 */
class LegacyInvoiceCreateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Student $student;
    private AcademicYear $year;
    private CashAccount $account;
    private Fee $fee;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('accountant');

        $stage = Stage::create(['name' => 'Начальная школа']);
        $grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => '1A', 'name_ar' => '1A']);
        $this->student = Student::create(['name' => 'Иван Иванов']);
        $this->year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'status' => 'active', 'is_active' => true,
        ]);
        $this->account = CashAccount::operating();
        app(CashSessionService::class)->open($this->account, $this->user);
        $this->fee = Fee::create(['name_ru' => 'Продлёнка', 'amount' => '1000.00', 'category' => Fee::CATEGORY_OTHER, 'is_active' => true]);
    }

    private function payload(string $token, array $overrides = []): array
    {
        return array_replace([
            'idempotency_key' => $token,
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-01',
            'pricing_date' => '2026-09-01',
            'fees' => [$this->fee->id],
            'cash_account_id' => $this->account->id,
            'initial_payment_amount' => '0.00',
        ], $overrides);
    }

    private function withPayment(string $token, string $amount = '400.00', array $overrides = []): array
    {
        return $this->payload($token, array_replace([
            'initial_payment_amount' => $amount, 'payment_method' => 'cash',
        ], $overrides));
    }

    private function store(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $payload);
    }

    /** @return array<string, int> */
    private function financialSnapshot(): array
    {
        return collect([
            'invoices', 'invoice_items', 'invoice_fee', 'invoice_installments', 'invoice_payments',
            'payment_allocations', 'cash_transactions', 'audit_logs',
        ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    // ----- 1. Form token ---------------------------------------------------

    public function test_create_form_renders_a_fresh_uuid_idempotency_key_per_render(): void
    {
        $tokens = collect([1, 2])->map(function () {
            $html = $this->actingAs($this->user)->get(route('dashboard.invoices.create'))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<input type="hidden" name="idempotency_key" value="([^"]+)">/', $html, $match));

            return $match[1];
        });

        $tokens->each(fn (string $token) => $this->assertTrue(Str::isUuid($token)));
        $this->assertNotSame($tokens[0], $tokens[1]);
    }

    // ----- 2/3. Same token + same payload replays --------------------------

    public function test_double_submit_with_initial_payment_writes_one_invoice_one_payment_one_cash_transaction(): void
    {
        $token = (string) Str::uuid();

        $first = $this->store($this->withPayment($token));
        $first->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $first->assertRedirect(route('dashboard.invoices.print', $invoice));
        $afterFirst = $this->financialSnapshot();

        $second = $this->store($this->withPayment($token));
        $second->assertSessionHasNoErrors();
        $second->assertRedirect(route('dashboard.invoices.print', $invoice));

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame(1, $afterFirst['invoices']);
        $this->assertSame(1, $afterFirst['invoice_items']);
        $this->assertSame(1, $afterFirst['invoice_payments']);
        $this->assertSame(1, $afterFirst['cash_transactions']);

        $payment = InvoicePayment::sole();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('400.00', (string) $payment->amount);
        $this->assertSame('600.00', (string) $invoice->fresh()->remaining_amount);
        $this->assertSame(DeterministicIdempotencyKey::derive($token, 'classic-invoice', 'issue'), $invoice->idempotency_key);
        $this->assertSame(DeterministicIdempotencyKey::derive($token, 'classic-invoice', 'payment'), $payment->idempotency_key);
    }

    public function test_double_submit_without_initial_payment_writes_one_invoice_and_no_payment(): void
    {
        $token = (string) Str::uuid();

        $this->store($this->payload($token))->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->payload($token))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard.invoices.print', $invoice));

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame(1, $afterFirst['invoices']);
        $this->assertSame(0, $afterFirst['invoice_payments']);
        $this->assertSame(0, $afterFirst['cash_transactions']);
    }

    public function test_double_submit_of_a_multi_item_invoice_with_split_initial_payment_replays_the_same_allocations(): void
    {
        $second = Fee::create(['name_ru' => 'Кружок', 'amount' => '500.00', 'category' => Fee::CATEGORY_EXTRA_CLASSES, 'is_active' => true]);
        $token = (string) Str::uuid();
        $payload = $this->withPayment($token, '700.00', [
            'fees' => [$this->fee->id, $second->id],
            'allocations' => [$this->fee->id => '400.00', $second->id => '300.00'],
        ]);

        $this->store($payload)->assertSessionHasNoErrors();
        $afterFirst = $this->financialSnapshot();
        $this->store($payload)->assertSessionHasNoErrors();

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame(2, $afterFirst['invoice_items']);
        $this->assertSame(1, $afterFirst['invoice_payments']);
        $this->assertSame(2, $afterFirst['payment_allocations']);
        $this->assertSame(1, $afterFirst['cash_transactions']);
    }

    public function test_a_retry_differing_only_in_the_free_text_note_replays(): void
    {
        $token = (string) Str::uuid();

        $this->store($this->withPayment($token, '400.00', ['notes' => 'Первая попытка']))->assertSessionHasNoErrors();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->withPayment($token, '400.00', ['notes' => 'Повтор']))->assertSessionHasNoErrors();

        $this->assertSame($afterFirst, $this->financialSnapshot());
    }

    // ----- 4/5. Same token + materially changed payload is rejected --------

    public function test_same_token_with_a_changed_invoice_payload_is_rejected_with_zero_writes(): void
    {
        $token = (string) Str::uuid();
        $this->store($this->withPayment($token))->assertSessionHasNoErrors();
        $original = Invoice::sole();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->withPayment($token, '400.00', ['due_date' => '2026-10-01']))
            ->assertSessionHasErrors(['idempotency_key' => 'Ключ повторного запроса уже использован для другого оформления счёта.']);

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame($original->id, Invoice::sole()->id);
        $this->assertSame('2026-09-01', Invoice::sole()->due_date->toDateString());
    }

    public function test_same_token_with_a_changed_initial_payment_amount_is_rejected_with_zero_writes(): void
    {
        $token = (string) Str::uuid();
        $this->store($this->withPayment($token, '400.00'))->assertSessionHasNoErrors();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->withPayment($token, '300.00'))
            ->assertSessionHasErrors(['idempotency_key' => 'Ключ повторного запроса уже использован для другого платежа.']);

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame('400.00', (string) InvoicePayment::sole()->amount);
        $this->assertSame('600.00', (string) Invoice::sole()->remaining_amount);
    }

    public function test_same_token_adding_an_initial_payment_the_original_did_not_have_is_rejected_with_zero_writes(): void
    {
        $token = (string) Str::uuid();
        $this->store($this->payload($token))->assertSessionHasNoErrors();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->withPayment($token, '300.00'))
            ->assertSessionHasErrors(['idempotency_key' => 'Ключ повторного запроса уже использован для другого оформления счёта.']);

        $this->assertSame($afterFirst, $this->financialSnapshot());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame('1000.00', (string) Invoice::sole()->remaining_amount);
    }

    public function test_same_token_dropping_the_original_initial_payment_is_rejected_with_zero_writes(): void
    {
        $token = (string) Str::uuid();
        $this->store($this->withPayment($token, '400.00'))->assertSessionHasNoErrors();
        $afterFirst = $this->financialSnapshot();

        $this->store($this->payload($token))
            ->assertSessionHasErrors(['idempotency_key' => 'Ключ повторного запроса уже использован для другого оформления счёта.']);

        $this->assertSame($afterFirst, $this->financialSnapshot());
    }

    // ----- 6. New token ----------------------------------------------------

    public function test_a_new_token_is_a_genuinely_new_submission(): void
    {
        $this->store($this->withPayment((string) Str::uuid()))->assertSessionHasNoErrors();
        $this->store($this->withPayment((string) Str::uuid()))->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, InvoicePayment::count());
        $this->assertSame(2, DB::table('cash_transactions')->count());
    }

    // ----- 7/8. Missing or malformed token ---------------------------------

    public function test_a_missing_idempotency_key_is_rejected_with_zero_writes(): void
    {
        $before = $this->financialSnapshot();
        $payload = $this->withPayment((string) Str::uuid());
        unset($payload['idempotency_key']);

        $this->store($payload)->assertSessionHasErrors(['idempotency_key' => 'Обновите страницу и попробуйте снова.']);

        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_a_malformed_idempotency_key_is_rejected_with_zero_writes(): void
    {
        $before = $this->financialSnapshot();

        $this->store($this->withPayment('not-a-uuid'))
            ->assertSessionHasErrors(['idempotency_key' => 'Не удалось подтвердить уникальность счёта. Обновите страницу.']);

        $this->assertSame($before, $this->financialSnapshot());
    }
}
