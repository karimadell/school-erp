<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCreationTest extends TestCase
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
        // Cash Operations Phase 4: payment_method=cash always resolves to
        // the canonical operating account server-side, regardless of what
        // cash_account_id a request submits — see
        // CashAccount::resolvePaymentAccountId(). Tests must open the
        // session on that same canonical account.
        $this->account = CashAccount::operating();
        // Phase 3: a cash collection requires an open drawer session.
        app(\App\Services\Finance\CashSessionService::class)->open($this->account, $this->user);
        $this->fee = Fee::create(['name_ru' => 'Обучение', 'amount' => '1000.00', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-01',
            'fees' => [$this->fee->id],
            'cash_account_id' => $this->account->id,
            'initial_payment_amount' => '0.00',
        ], $overrides);
    }

    public function test_creation_recalculates_and_persists_year_due_date_and_both_line_structures(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload());

        $response->assertSessionHasNoErrors();
        $response->assertRedirectContains('/dashboard/invoices/');
        $invoice = Invoice::sole();
        $response->assertRedirect(route('dashboard.invoices.print', $invoice));
        $response->assertSessionHas('success', 'Счёт успешно создан. Все суммы рассчитаны в EGP.');
        $this->assertSame('1000.00', $invoice->total_amount);
        $this->assertSame('1000.00', $invoice->subtotal_amount);
        $this->assertSame('EGP', $invoice->currency);
        $this->assertSame($this->user->id, $invoice->created_by);
        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->invoice_number);
        $this->assertSame($this->year->id, $invoice->academic_year_id);
        $this->assertSame('2026-09-01', $invoice->due_date->toDateString());
        $this->assertSame('1000.00', InvoiceItem::sole()->amount);
        $this->assertSame('1000.00', number_format((float) $invoice->fees()->first()->pivot->amount, 2, '.', ''));
    }

    public function test_browser_calculated_fields_are_rejected(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'invoice_number' => 'OWN-NUMBER', 'currency' => 'RUB', 'subtotal_amount' => '0.01',
            'created_by' => User::factory()->create()->id, 'total_amount' => '0.01',
            'paid_amount' => '999.00', 'remaining_amount' => '0.00', 'status' => 'paid',
        ]));

        $response->assertSessionHasErrors([
            'invoice_number', 'currency', 'subtotal_amount', 'created_by',
            'total_amount', 'paid_amount', 'remaining_amount', 'status',
        ]);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_zero_initial_payment_creates_no_payment_or_cash_transaction(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'cash_account_id' => null,
            'payment_method' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertNull($invoice->payment_method);
        $this->assertNull($invoice->cash_account_id);
    }

    public function test_initial_payment_and_cash_posting_are_atomic(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'initial_payment_amount' => '250.00', 'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('250.00', InvoicePayment::sole()->amount);
        $this->assertSame('250.00', CashTransaction::sole()->amount);
        $this->assertSame('250.00', $this->account->fresh()->balance);
        $this->assertSame(Invoice::STATUS_PARTIAL, Invoice::sole()->status);
    }

    public function test_exact_full_payment_succeeds_with_one_invoice_one_payment_one_cash_transaction(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'initial_payment_amount' => '1000.00', 'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('1000.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame('1000.00', InvoicePayment::sole()->amount);
        $this->assertSame('1000.00', CashTransaction::sole()->amount);
        $this->assertSame('1000.00', $this->account->fresh()->balance);
    }

    public function test_failed_payment_recording_rolls_back_invoice_and_items(): void
    {
        // Close the canonical operating account's session (opened in
        // setUp()): cash always resolves to that one account regardless of
        // what cash_account_id the request submits, so this is the only
        // way left to make InvoicePaymentService::record() throw partway
        // through the single store() transaction. Nothing — not the
        // invoice, not its items, not any pivot row — should survive.
        $sessions = app(\App\Services\Finance\CashSessionService::class);
        $sessions->close($sessions->activeFor($this->account), $this->user, (string) $this->account->balance);

        $response = $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'initial_payment_amount' => '250.00', 'payment_method' => 'cash',
        ]));

        $response->assertSessionHasErrors('payment_method');
        $this->assertStringContainsString(
            'Для приёма наличных нужна открытая кассовая смена',
            session('errors')->first('payment_method'),
        );
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_overpayment_is_rejected_in_russian(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload([
            'initial_payment_amount' => '1000.01', 'payment_method' => 'cash',
        ]));

        $response->assertSessionHasErrors('initial_payment_amount');
        $this->assertStringContainsString('не может превышать сумму счёта', session('errors')->first('initial_payment_amount'));
        $this->assertDatabaseCount('invoices', 0);
    }
}
