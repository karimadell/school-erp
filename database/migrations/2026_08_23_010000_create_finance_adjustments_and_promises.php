<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('student_service_subscriptions')->nullOnDelete();
            $table->foreignId('fee_price_id')->constrained('fee_prices')->restrictOnDelete();
            $table->date('coverage_start');
            $table->date('coverage_end');
            $table->enum('billing_unit', ['monthly', 'daily']);
            $table->string('payment_period', 50)->nullable();
            $table->string('option_type', 100)->nullable();
            $table->string('option_value', 255)->nullable();
            $table->string('grade_group', 100)->nullable();
            $table->string('item', 100)->nullable();
            $table->string('size', 50)->nullable();
            $table->decimal('original_unit_price', 12, 2);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'fee_id', 'coverage_start', 'coverage_end'], 'coverage_student_fee_dates_idx');
        });

        Schema::create('tariff_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_coverage_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_fee_price_id')->nullable()->constrained('fee_prices')->restrictOnDelete();
            $table->foreignId('new_fee_price_id')->constrained('fee_prices')->restrictOnDelete();
            $table->enum('status', ['posted'])->default('posted');
            $table->enum('kind', ['debit', 'credit']);
            $table->decimal('total_difference', 12, 2);
            $table->string('currency', 3)->default('EGP');
            $table->foreignId('posting_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['service_coverage_id', 'previous_fee_price_id', 'new_fee_price_id'], 'tariff_adjustment_transition_unique');
            $table->index(['student_id', 'status']);
        });

        Schema::create('tariff_adjustment_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tariff_adjustment_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_coverage_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_fee_price_id')->nullable()->constrained('fee_prices')->restrictOnDelete();
            $table->foreignId('new_fee_price_id')->constrained('fee_prices')->restrictOnDelete();
            $table->date('segment_start');
            $table->date('segment_end');
            $table->enum('billing_unit', ['monthly', 'daily']);
            $table->unsignedInteger('units');
            $table->decimal('previous_unit_price', 12, 2);
            $table->decimal('new_unit_price', 12, 2);
            $table->decimal('difference_per_unit', 12, 2);
            $table->decimal('total_difference', 12, 2);
            $table->timestamps();
            $table->unique(
                ['service_coverage_id', 'previous_fee_price_id', 'new_fee_price_id', 'segment_start', 'segment_end'],
                'tariff_adjustment_segment_unique'
            );
            $table->unique(['service_coverage_id', 'segment_start'], 'tariff_segment_start_unique');
            $table->unique(['service_coverage_id', 'segment_end'], 'tariff_segment_end_unique');
        });

        Schema::create('student_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_adjustment_id')->unique()->constrained('tariff_adjustments')->restrictOnDelete();
            $table->decimal('original_amount', 12, 2);
            $table->decimal('consumed_amount', 12, 2)->default(0);
            $table->decimal('available_amount', 12, 2);
            $table->enum('status', ['available', 'partially_consumed', 'consumed']);
            $table->timestamps();
            $table->index(['student_id', 'status']);
        });

        Schema::create('student_credit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_credit_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['student_id', 'invoice_id']);
        });

        Schema::create('promise_to_pays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('promised_amount', 12, 2);
            $table->date('expected_payment_date');
            $table->text('note')->nullable();
            $table->enum('status', ['open', 'fulfilled', 'cancelled'])->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_note')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'status', 'expected_payment_date'], 'promise_student_status_date_idx');
            $table->unique('invoice_payment_id');
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('promise_to_pays');
        Schema::dropIfExists('student_credit_applications');
        Schema::dropIfExists('student_credits');
        Schema::dropIfExists('tariff_adjustment_segments');
        Schema::dropIfExists('tariff_adjustments');
        Schema::dropIfExists('service_coverages');
    }

    private function addCheckConstraints(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'ALTER TABLE service_coverages ADD CONSTRAINT coverage_dates_check CHECK (coverage_end >= coverage_start)',
            'ALTER TABLE service_coverages ADD CONSTRAINT coverage_price_check CHECK (original_unit_price >= 0)',
            "ALTER TABLE tariff_adjustments ADD CONSTRAINT adjustment_posting_check CHECK ((kind = 'debit' AND total_difference > 0 AND posting_invoice_id IS NOT NULL) OR (kind = 'credit' AND total_difference < 0 AND posting_invoice_id IS NULL))",
            'ALTER TABLE tariff_adjustment_segments ADD CONSTRAINT segment_dates_check CHECK (segment_end >= segment_start)',
            'ALTER TABLE tariff_adjustment_segments ADD CONSTRAINT segment_units_check CHECK (units > 0)',
            'ALTER TABLE tariff_adjustment_segments ADD CONSTRAINT segment_amount_check CHECK (total_difference = difference_per_unit * units AND difference_per_unit = new_unit_price - previous_unit_price)',
            'ALTER TABLE student_credits ADD CONSTRAINT credit_amounts_check CHECK (original_amount > 0 AND consumed_amount >= 0 AND available_amount >= 0 AND consumed_amount <= original_amount AND available_amount = original_amount - consumed_amount)',
            'ALTER TABLE student_credit_applications ADD CONSTRAINT credit_application_amount_check CHECK (amount > 0)',
            'ALTER TABLE promise_to_pays ADD CONSTRAINT promise_amount_check CHECK (promised_amount > 0)',
            "ALTER TABLE promise_to_pays ADD CONSTRAINT promise_state_check CHECK ((status = 'open' AND fulfilled_at IS NULL AND fulfilled_by IS NULL AND invoice_payment_id IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL) OR (status = 'fulfilled' AND fulfilled_at IS NOT NULL AND fulfilled_by IS NOT NULL AND invoice_payment_id IS NOT NULL AND cancelled_at IS NULL AND cancelled_by IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND fulfilled_at IS NULL AND fulfilled_by IS NULL AND invoice_payment_id IS NULL))",
        ];
        foreach ($checks as $check) {
            \Illuminate\Support\Facades\DB::statement($check);
        }
    }
};
