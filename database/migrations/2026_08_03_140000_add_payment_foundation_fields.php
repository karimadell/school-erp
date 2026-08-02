<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('payment_number')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->string('idempotency_hash', 64)->nullable();
        });

        $this->backfillPayments();

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unique('payment_number');
            $table->unique('idempotency_key');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('invoice_payment_id')->nullable()->unique()
                ->constrained('invoice_payments')->restrictOnDelete();
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_payment_id');
        });
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropUnique(['payment_number']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['payment_number', 'idempotency_key', 'idempotency_hash']);
        });
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    private function backfillPayments(): void
    {
        DB::table('invoice_payments')->whereNull('payment_number')->orderBy('id')->each(function ($payment): void {
            $sourceDate = $payment->created_at ?? $payment->paid_at ?? null;
            $year = preg_match('/^\d{4}/', (string) $sourceDate, $matches) ? $matches[0] : '0000';
            DB::table('invoice_payments')->where('id', $payment->id)->update([
                'payment_number' => sprintf('PAY-%s-%06d', $year, $payment->id),
            ]);
        });
    }
};
