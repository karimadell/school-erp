<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4D-A Mass Billing (Checkpoint 3): narrow, additive columns the execution
 * path needs on top of the Checkpoint 2 schema — a trigger type and a compact
 * failure summary on runs, and per-student snapshot context (enrollment, fee,
 * class/calculation snapshot) plus a unique invoice linkage on run items.
 *
 * The unique index on billing_run_items.invoice_id is the database-level
 * guarantee that a generated invoice can be linked to at most one run item,
 * backing the application-layer idempotency guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_runs', function (Blueprint $table) {
            $table->string('trigger_type')->default('manual')->after('uuid');
            $table->json('failure_summary')->nullable()->after('finished_at');
        });

        Schema::table('billing_run_items', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()->after('student_id')->constrained('enrollments')->nullOnDelete();
            $table->foreignId('fee_id')->nullable()->after('enrollment_id')->constrained('fees')->nullOnDelete();
            $table->json('context')->nullable()->after('amount');

            // One run item per generated invoice (nullable → many un-generated
            // rows may keep invoice_id null; only real linkages must be unique).
            $table->unique('invoice_id', 'billing_run_items_invoice_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_run_items', function (Blueprint $table) {
            $table->dropUnique('billing_run_items_invoice_id_unique');
            $table->dropConstrainedForeignId('fee_id');
            $table->dropConstrainedForeignId('enrollment_id');
            $table->dropColumn('context');
        });

        Schema::table('billing_runs', function (Blueprint $table) {
            $table->dropColumn(['trigger_type', 'failure_summary']);
        });
    }
};
