<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_salaries', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->change();
            $table->decimal('base_salary', 12, 2)->change();
            $table->decimal('bonus', 12, 2)->change();
            $table->decimal('deductions', 12, 2)->change();
            $table->decimal('net_salary', 12, 2)->change();
            $table->foreignId('employee_user_id')->nullable()->after('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('employee_name')->nullable()->after('employee_user_id');
            $table->string('position')->nullable()->after('employee_name');
            $table->decimal('allowances', 12, 2)->default(0)->after('bonus');
            $table->string('status')->default('draft')->after('salary_month');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->foreignId('cash_transaction_id')->nullable()->after('paid_at')->unique()->constrained('cash_transactions')->restrictOnDelete();
            $table->unique(['employee_user_id', 'salary_month'], 'employee_payroll_user_month_unique');
            $table->unique(['teacher_id', 'salary_month'], 'employee_payroll_teacher_month_unique');
        });

        Schema::create('employee_salary_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('position')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_user_id', 'effective_from'], 'employee_salary_rate_start_unique');
            $table->index(['employee_user_id', 'effective_from', 'effective_to'], 'employee_salary_rate_lookup');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_salary_id')->constrained('teacher_salaries')->restrictOnDelete();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['teacher_salary_id', 'type']);
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
            $table->foreignId('teacher_salary_id')->nullable()->unique()->constrained('teacher_salaries')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE teacher_salaries ADD CONSTRAINT employee_payroll_status_check CHECK (status IN ('draft', 'approved', 'paid'))");
            DB::statement('ALTER TABLE teacher_salaries ADD CONSTRAINT employee_payroll_amounts_check CHECK (base_salary >= 0 AND bonus >= 0 AND allowances >= 0 AND deductions >= 0 AND net_salary >= 0)');
            DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustment_type_check CHECK (type IN ('bonus', 'allowance', 'deduction'))");
            DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustment_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE employee_salary_rates ADD CONSTRAINT employee_salary_rate_amount_check CHECK (amount >= 0)');
            DB::statement('ALTER TABLE employee_salary_rates ADD CONSTRAINT employee_salary_rate_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        }
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_salary_id');
            $table->dropColumn('payment_method');
        });
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('employee_salary_rates');
        Schema::table('teacher_salaries', function (Blueprint $table) {
            $table->dropUnique('employee_payroll_user_month_unique');
            $table->dropUnique('employee_payroll_teacher_month_unique');
            $table->dropConstrainedForeignId('cash_transaction_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('employee_user_id');
            $table->dropColumn(['employee_name', 'position', 'allowances', 'status', 'approved_at', 'paid_at']);
        });
    }
};
