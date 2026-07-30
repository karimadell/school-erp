<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5: the centerpiece of the finance/service foundation. Links an
 * Enrollment (already year- and mode-scoped, Batches 3-4) to a Fee (reused
 * as the service catalog, not a new parallel entity).
 *
 * unique(enrollment_id, fee_id): since an Enrollment already uniquely
 * represents one (student, academic_year) pair, this gives "at most one
 * subscription per fee per year" for free — the mechanism approved for
 * registration-fee duplicate prevention (policy decision 3), reused
 * generically for every fee rather than special-cased.
 *
 * negotiated_price / negotiated_reason / negotiated_by: a per-student price
 * override, distinct from Fee/FeePrice's catalog price. Approved policy
 * (decision 6): requires a reason and records the acting user; enforced and
 * audited at the application layer (StudentServiceSubscriptionService), not
 * by a database constraint, since "reason required only when overriding" is
 * conditional logic a CHECK constraint can't express portably across
 * MySQL/SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_service_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_id')->constrained()->restrictOnDelete();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('quantity')->default(1);
            $table->enum('status', ['active', 'suspended', 'cancelled', 'completed'])->default('active');

            $table->decimal('negotiated_price', 10, 2)->nullable();
            $table->text('negotiated_reason')->nullable();
            $table->foreignId('negotiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['enrollment_id', 'fee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_service_subscriptions');
    }
};
