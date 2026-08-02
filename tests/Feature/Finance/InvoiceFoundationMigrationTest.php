<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_columns_defaults_and_nullable_payment_fields_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('invoices', [
            'invoice_number', 'currency', 'subtotal_amount', 'created_by',
        ]));

        $student = Student::create(['name' => 'Ученик']);
        $id = DB::table('invoices')->insertGetId([
            'student_id' => $student->id,
            'total_amount' => '0.00',
            'status' => 'unpaid',
            'payment_method' => null,
            'cash_account_id' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = DB::table('invoices')->find($id);
        $this->assertSame('EGP', $invoice->currency);
        $this->assertNull($invoice->payment_method);
        $this->assertNull($invoice->cash_account_id);
    }

    public function test_existing_rows_are_backfilled_by_canonical_fallback_order_without_deletion(): void
    {
        $migration = require database_path('migrations/2026_08_02_120000_add_invoice_foundation_fields_to_invoices_table.php');

        $student = Student::create(['name' => 'Ученик']);
        $account = CashAccount::create(['name' => 'Касса', 'type' => 'cash']);
        $fees = collect(range(1, 2))->map(fn (int $number) => Fee::create([
            'name_ru' => "Услуга {$number}", 'amount' => '100.00', 'is_active' => true,
        ]));

        $invoiceIds = collect([
            ['total_amount' => '90.00', 'discount_amount' => '10.00'],
            ['total_amount' => '180.00', 'discount_amount' => '20.00'],
            ['total_amount' => '270.00', 'discount_amount' => '30.00'],
        ])->map(fn (array $amounts) => DB::table('invoices')->insertGetId([
            'student_id' => $student->id,
            'invoice_number' => null,
            'currency' => 'EGP',
            'subtotal_amount' => null,
            'total_amount' => $amounts['total_amount'],
            'discount_amount' => $amounts['discount_amount'],
            'paid_amount' => '0.00',
            'remaining_amount' => $amounts['total_amount'],
            'status' => 'unpaid',
            'payment_method' => 'cash',
            'cash_account_id' => $account->id,
            'paid_at' => null,
            'created_at' => '2026-08-02 10:00:00',
            'updated_at' => '2026-08-02 10:00:00',
        ]));

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceIds[0], 'fee_id' => $fees[0]->id,
            'description' => 'Снимок услуги', 'amount' => '125.50',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_fee')->insert([
            'invoice_id' => $invoiceIds[1], 'fee_id' => $fees[1]->id, 'amount' => '215.75',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $backfill = new ReflectionMethod($migration, 'backfillInvoices');
        $backfill->invoke($migration);

        $this->assertDatabaseCount('invoices', 3);
        $this->assertSame('125.50', number_format((float) DB::table('invoices')->find($invoiceIds[0])->subtotal_amount, 2, '.', ''));
        $this->assertSame('215.75', number_format((float) DB::table('invoices')->find($invoiceIds[1])->subtotal_amount, 2, '.', ''));
        $this->assertSame('300.00', number_format((float) DB::table('invoices')->find($invoiceIds[2])->subtotal_amount, 2, '.', ''));
        $this->assertSame(3, DB::table('invoices')->distinct()->count('invoice_number'));
        $this->assertSame('INV-2026-'.str_pad((string) $invoiceIds[0], 6, '0', STR_PAD_LEFT), DB::table('invoices')->find($invoiceIds[0])->invoice_number);
    }
}
