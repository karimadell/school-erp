<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass (P0/HIGH — invoice issuance
 * idempotency). Mirrors invoice_payments.idempotency_key/idempotency_hash
 * exactly (2026_08_03_140000_add_payment_foundation_fields) — nullable,
 * UNIQUE when present, so a genuine concurrent race (two simultaneous
 * requests, same key) is caught by the database's own unique constraint,
 * not just application-level double-checking.
 *
 * Purely additive, nullable — every existing/unmodified caller of
 * InvoiceIssuanceService::issue() that never passes a key keeps working
 * exactly as before (idempotency is opt-in per call).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('id');
            $table->string('idempotency_hash', 64)->nullable()->after('idempotency_key');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_hash']);
        });
    }
};
