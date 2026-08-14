<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnumMigrationPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_status_keeps_portable_allowed_values_default_and_nullability(): void
    {
        $column = collect(Schema::getColumns('invoices'))->firstWhere('name', 'status');
        $triggerSql = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'invoices_status_check_%'"))
            ->pluck('sql')
            ->implode(' ');

        $this->assertFalse($column['nullable']);
        $this->assertStringContainsString('unpaid', (string) $column['default']);

        foreach (['unpaid', 'partial', 'paid', 'cancelled'] as $status) {
            $this->assertStringContainsString("'{$status}'", $triggerSql);
        }

        $this->assertInvalidValueIsRejected('invoices', 'status');
    }

    public function test_cash_transaction_category_keeps_refund_default_and_nullability(): void
    {
        $column = collect(Schema::getColumns('cash_transactions'))->firstWhere('name', 'category');
        $triggerSql = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'cash_transactions_category_check_%'"))
            ->pluck('sql')
            ->implode(' ');

        $this->assertFalse($column['nullable']);
        $this->assertStringContainsString('income', (string) $column['default']);

        foreach (['income', 'expense', 'transfer', 'refund'] as $category) {
            $this->assertStringContainsString("'{$category}'", $triggerSql);
        }

        $this->assertInvalidValueIsRejected('cash_transactions', 'category');
    }

    private function assertInvalidValueIsRejected(string $table, string $column): void
    {
        try {
            DB::table($table)->insert([$column => 'not-allowed']);
            $this->fail("{$table}.{$column} accepted a value outside its allowed set.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString("invalid {$table}.{$column}", $exception->getMessage());
        }
    }
}
