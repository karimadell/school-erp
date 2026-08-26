<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash Operations Phase 1.
 *
 * transfer_type classifies a CashTransfer for future reporting (the old
 * workbook's "Выручка"-as-expense line is really a transfer, per the
 * Phase 1 brief) without string-matching notes/purpose text:
 *   - internal: generic account-to-account transfer (existing behaviour)
 *   - handover: Операционная касса -> Касса владельца (daily handover)
 *   - owner_return: Касса владельца -> Операционная касса
 *
 * idempotency_key/idempotency_hash mirror invoice_payments' existing
 * pattern (2026_08_03_140000_add_payment_foundation_fields) so a replayed
 * transfer submission (double-click, retry) returns the original transfer
 * instead of posting money twice.
 */
return new class extends Migration
{
    private const TYPES = ['internal', 'handover', 'owner_return'];

    public function up(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->string('transfer_type')->default('internal')->after('to_account_id');
            $table->uuid('idempotency_key')->nullable()->unique()->after('receipt_number');
            $table->string('idempotency_hash', 64)->nullable()->after('idempotency_key');
        });

        $this->applyTransferTypeGuard();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_transfers DROP CONSTRAINT IF EXISTS cash_transfers_transfer_type_check');
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_transfers_transfer_type_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cash_transfers_transfer_type_check_update');
        }

        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['transfer_type', 'idempotency_key', 'idempotency_hash']);
        });
    }

    /**
     * Same portable enum-guard approach as
     * 2026_08_09_100200_add_refund_category_to_cash_transactions: a real
     * CHECK constraint on postgres, an equivalent trigger pair on sqlite,
     * nothing extra needed on MySQL (its native ENUM column already
     * rejects invalid values once cast — kept as a plain string here for
     * cross-database portability, matching cash_transactions.category's
     * own history).
     */
    private function applyTransferTypeGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), self::TYPES));

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE cash_transfers ADD CONSTRAINT cash_transfers_transfer_type_check CHECK (transfer_type IN ({$allowed}))");

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER cash_transfers_transfer_type_check_insert BEFORE INSERT ON cash_transfers WHEN NEW.transfer_type NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_transfers.transfer_type'); END");
            DB::unprepared("CREATE TRIGGER cash_transfers_transfer_type_check_update BEFORE UPDATE OF transfer_type ON cash_transfers WHEN NEW.transfer_type NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_transfers.transfer_type'); END");
        }
    }
};
