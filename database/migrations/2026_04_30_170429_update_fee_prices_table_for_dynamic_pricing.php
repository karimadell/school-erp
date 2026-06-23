<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_prices', 'grade_id')) {
                $table->foreignId('grade_id')
                    ->nullable()
                    ->after('fee_id')
                    ->constrained('grades')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('fee_prices', 'payment_period')) {
                $table->string('payment_period')->nullable()->after('grade_id');
            }

            if (! Schema::hasColumn('fee_prices', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            if (! Schema::hasColumn('fee_prices', 'option_type')) {
                $table->string('option_type')->nullable()->after('end_date');
            }

            if (! Schema::hasColumn('fee_prices', 'option_value')) {
                $table->string('option_value')->nullable()->after('option_type');
            }

            if (! Schema::hasColumn('fee_prices', 'notes')) {
                $table->text('notes')->nullable()->after('option_value');
            }

            if (! Schema::hasColumn('fee_prices', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (Schema::hasColumn('fee_prices', 'grade_id')) {
                $table->dropConstrainedForeignId('grade_id');
            }

            if (Schema::hasColumn('fee_prices', 'payment_period')) {
                $table->dropColumn('payment_period');
            }

            if (Schema::hasColumn('fee_prices', 'end_date')) {
                $table->dropColumn('end_date');
            }

            if (Schema::hasColumn('fee_prices', 'option_type')) {
                $table->dropColumn('option_type');
            }

            if (Schema::hasColumn('fee_prices', 'option_value')) {
                $table->dropColumn('option_value');
            }

            if (Schema::hasColumn('fee_prices', 'notes')) {
                $table->dropColumn('notes');
            }

            if (Schema::hasColumn('fee_prices', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};