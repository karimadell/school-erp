<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #2 (HIGH 2 — Quick Registration
 * operation-level idempotency).
 *
 * Pass #1's invoice-level idempotency (invoices.idempotency_key) is too
 * late — a retried Quick Registration submission still creates a
 * duplicate Student/Enrollment before ever reaching invoice issuance,
 * since Student creation itself has no dedup at all. The real
 * idempotency unit for Quick Registration is the WHOLE operation graph:
 * Student + Enrollment + Invoice + Payments + Coverage.
 *
 * Mirrors invoice_payments/invoices' own idempotency_key/idempotency_hash
 * shape and naming (nullable-elsewhere-but-required-here UUID, UNIQUE) —
 * named payload_hash here (not idempotency_hash) only because this row's
 * OWN idempotency_key is never nullable (unlike invoices/invoice_payments,
 * this table only ever exists for a key-bearing submission), so there is
 * no "hash only when a key is present" branching to mirror.
 *
 * status/student_id/enrollment_id/invoice_id are mutable (unlike every
 * other Phase 2D table, which is write-once/immutable) — this row is a
 * genuine in-flight tracking record: created 'pending' before Student
 * creation begins, updated to 'completed' with the resulting ids ONLY on
 * a real, committed success (see QuickStudentRegistrationService::
 * register()) — if the surrounding transaction rolls back, this row
 * rolls back with it (created inside the same transaction), so a failed
 * attempt never leaves a stale 'pending' or falsely 'completed' row
 * behind at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_registration_operations', function (Blueprint $table) {
            $table->id();

            $table->uuid('idempotency_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('pending');

            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_registration_operations');
    }
};
