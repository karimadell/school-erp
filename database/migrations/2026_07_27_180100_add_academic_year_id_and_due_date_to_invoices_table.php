<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5: Invoice was not year-scoped at all. academic_year_id is added
 * for reporting/scoping. due_date is added because it did not exist
 * anywhere on Invoice — the overdue-balance policy (decision 4) needs an
 * age-based check ("has this invoice been unpaid for more than N days"),
 * which is impossible without a due date. Both nullable and additive.
 *
 * Also relaxes the pre-existing invoices.paid_at column to nullable: it was
 * created NOT NULL with no default, which means an "unpaid" invoice (no
 * payment yet, so no paid_at) could never actually be inserted — a latent
 * bug that predates this batch, discovered here because it directly
 * blocked writing legitimate tests against Invoice. No existing data is
 * affected (the invoices table is empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'academic_year_id')) {
                $table->foreignId('academic_year_id')
                    ->nullable()
                    ->after('student_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('invoices', 'due_date')) {
                $table->date('due_date')->nullable()->after('paid_at');
            }

            $table->timestamp('paid_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'due_date')) {
                $table->dropColumn('due_date');
            }

            if (Schema::hasColumn('invoices', 'academic_year_id')) {
                $table->dropConstrainedForeignId('academic_year_id');
            }

            $table->timestamp('paid_at')->nullable(false)->change();
        });
    }
};
