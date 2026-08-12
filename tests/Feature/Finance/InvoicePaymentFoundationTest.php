<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class InvoicePaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePaymentService $service;
    private Invoice $invoice;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoicePaymentService::class);
        $student = Student::create(['name' => 'Ученик']);
        $this->invoice = Invoice::create([
            'student_id' => $student->id, 'total_amount' => '1000.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1000.00', 'status' => Invoice::STATUS_UNPAID,
        ]);
        $this->account = CashAccount::create(['name' => 'Касса', 'type' => 'cash']);
        // Phase 3: a cash collection requires an open drawer session.
        \App\Models\CashSession::create([
            'cash_account_id' => $this->account->id,
            'opened_by' => \App\Models\User::factory()->create(['is_active' => true])->id,
            'opened_at' => now(),
            'opening_expected' => '0.00',
            'opening_expected_source' => \App\Models\CashSession::SOURCE_ACCOUNT_BALANCE,
            'status' => \App\Models\CashSession::STATUS_OPEN,
        ]);
    }

    private function pay(string $amount, ?string $key = null): InvoicePayment
    {
        return $this->service->record(
            invoiceId: $this->invoice->id, cashAccountId: $this->account->id,
            amount: $amount, paymentMethod: 'cash', idempotencyKey: $key ?? (string) Str::uuid(),
        );
    }

    public function test_payment_number_and_cash_link_are_unique_and_persisted_once(): void
    {
        $first = $this->pay('100.00');
        $second = $this->pay('100.00');

        $this->assertSame(InvoicePayment::numberFor($first->id, now()->format('Y')), $first->payment_number);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $first->payment_number);
        $this->assertNotSame($first->payment_number, $second->payment_number);
        $this->assertSame($first->id, $first->cashTransaction->invoice_payment_id);
        $this->assertSame($this->invoice->id, $first->cashTransaction->invoicePayment->invoice_id);
        $this->assertDatabaseCount('invoice_payments', 2);
        $this->assertDatabaseCount('cash_transactions', 2);
    }

    public function test_same_idempotency_key_returns_the_original_payment_without_double_posting(): void
    {
        $key = (string) Str::uuid();
        $first = $this->pay('250.00', $key);
        $replay = $this->pay('250.00', $key);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertSame('250.00', $this->account->fresh()->balance);
        $this->assertSame('250.00', $this->invoice->fresh()->paid_amount);

        $this->expectException(ValidationException::class);
        $this->pay('251.00', $key);
    }

    public function test_status_is_derived_from_canonical_payments(): void
    {
        $this->pay('400.00');
        $invoice = $this->invoice->fresh();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame('400.00', $invoice->paid_amount);
        $this->assertSame('600.00', $invoice->remaining_amount);

        $this->pay('600.00');
        $invoice = $this->invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('1000.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
    }

    public function test_invalid_payment_requests_are_rejected(): void
    {
        foreach (['0.00', '-1.00', '1000.01'] as $amount) {
            try {
                $this->pay($amount);
                $this->fail("Сумма {$amount} должна быть отклонена.");
            } catch (ValidationException) {
                $this->assertDatabaseCount('invoice_payments', 0);
            }
        }

        try {
            $this->service->record($this->invoice->id, 999999, '1.00', 'cash', (string) Str::uuid());
            $this->fail('Отсутствующая касса должна быть отклонена.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('cash_transactions', 0);
        }

        $this->account->update(['is_active' => false]);
        try {
            $this->pay('1.00');
            $this->fail('Неактивная касса должна быть отклонена.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoice_payments', 0);
        }
        $this->account->update(['is_active' => true]);

        foreach ([[999999, $this->account->id, 'cash'], [$this->invoice->id, $this->account->id, 'unknown']] as [$invoiceId, $accountId, $method]) {
            try {
                $this->service->record($invoiceId, $accountId, '1.00', $method, (string) Str::uuid());
                $this->fail('Некорректные реквизиты платежа должны быть отклонены.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('invoice_payments', 0);
            }
        }

        $this->service->record($this->invoice->id, $this->account->id, '1000.00', 'cash', (string) Str::uuid());
        $this->expectException(ValidationException::class);
        $this->pay('1.00');
    }

    public function test_failure_rolls_back_payment_cash_and_balance_atomically(): void
    {
        CashTransaction::created(fn () => throw new RuntimeException('Ошибка кассового проведения'));

        try {
            $this->pay('100.00');
            $this->fail('Ожидалась ошибка проведения.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('invoice_payments', 0);
            $this->assertDatabaseCount('cash_transactions', 0);
            $this->assertSame('0.00', $this->account->fresh()->balance);
            $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        }
        CashTransaction::flushEventListeners();
        CashTransaction::clearBootedModels();
    }

    public function test_posted_payment_is_immutable_and_cannot_be_deleted(): void
    {
        $payment = $this->pay('100.00');
        foreach (['amount' => '90.00', 'cash_account_id' => $this->account->id + 1, 'invoice_id' => $this->invoice->id + 1, 'payment_number' => 'PAY-2000-000001'] as $field => $value) {
            try {
                $payment->refresh()->update([$field => $value]);
                $this->fail("Поле {$field} не должно изменяться.");
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(LogicException::class);
        $payment->delete();
    }

    public function test_historical_payments_receive_deterministic_numbers_without_deletion(): void
    {
        $id = DB::table('invoice_payments')->insertGetId([
            'invoice_id' => $this->invoice->id, 'cash_account_id' => $this->account->id,
            'amount' => '10.00', 'paid_at' => '2024-05-01 12:00:00',
            'created_at' => '2024-05-01 12:00:00', 'updated_at' => '2024-05-01 12:00:00',
        ]);
        $migration = require database_path('migrations/2026_08_03_140000_add_payment_foundation_fields.php');
        $method = new ReflectionMethod($migration, 'backfillPayments');
        $method->invoke($migration);

        $this->assertSame("PAY-2024-".str_pad((string) $id, 6, '0', STR_PAD_LEFT), DB::table('invoice_payments')->find($id)->payment_number);
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertTrue(Schema::hasColumns('invoice_payments', ['payment_number', 'idempotency_key', 'idempotency_hash']));
        $this->assertTrue(Schema::hasColumn('cash_transactions', 'invoice_payment_id'));
    }
}
