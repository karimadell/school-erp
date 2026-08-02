<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('status')->default('active')->index();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestamp('registration_fee_charged_at')->nullable();
        });

        Schema::table('student_service_subscriptions', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2)->nullable();
            $table->json('metadata')->nullable();
        });

        DB::table('invoice_items')->update([
            'unit_price' => DB::raw('amount'),
            'remaining_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'quantity', 'paid_amount', 'remaining_amount', 'metadata']);
        });
        Schema::table('student_service_subscriptions', fn (Blueprint $table) => $table->dropColumn('metadata'));
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropColumn('registration_fee_charged_at'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('status'));
    }
};
