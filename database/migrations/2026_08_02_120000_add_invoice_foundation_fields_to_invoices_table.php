<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds tables for nullable column changes. Temporarily
        // disabling FK enforcement prevents that rebuild from cascading into
        // historical invoice_items, invoice_fee, and payment rows.
        Schema::disableForeignKeyConstraints();

        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('invoice_number')->nullable()->after('id');
                $table->string('currency', 3)->default('EGP')->after('invoice_number');
                $table->decimal('subtotal_amount', 12, 2)->nullable()->after('customer_name');
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });

            Schema::table('invoices', function (Blueprint $table) {
                $table->string('payment_method')->nullable()->change();
                $table->foreignId('cash_account_id')->nullable()->change();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->backfillInvoices();

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('invoice_number', 'invoices_invoice_number_unique');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique('invoices_invoice_number_unique');
                $table->dropConstrainedForeignId('created_by');
                $table->dropColumn(['invoice_number', 'currency', 'subtotal_amount']);
            });

            // Restoring NOT NULL is safe only when no records created under
            // the new nullable contract contain null. Otherwise keep the
            // columns nullable rather than making rollback destructive.
            if (! DB::table('invoices')->whereNull('payment_method')->exists()) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->string('payment_method')->nullable(false)->change();
                });
            }

            if (! DB::table('invoices')->whereNull('cash_account_id')->exists()) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->foreignId('cash_account_id')->nullable(false)->change();
                });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function backfillInvoices(): void
    {
        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) {
            foreach ($invoices as $invoice) {
                $subtotal = $this->subtotalFor($invoice);
                $year = $invoice->created_at
                    ? substr((string) $invoice->created_at, 0, 4)
                    : '0000';

                DB::table('invoices')->where('id', $invoice->id)->update([
                    'invoice_number' => sprintf('INV-%s-%06d', $year, $invoice->id),
                    'currency' => 'EGP',
                    'subtotal_amount' => $subtotal,
                ]);
            }
        }, 'id');
    }

    private function subtotalFor(object $invoice): string
    {
        if (Schema::hasTable('invoice_items')) {
            $itemCount = DB::table('invoice_items')->where('invoice_id', $invoice->id)->count();

            if ($itemCount > 0) {
                return $this->money(DB::table('invoice_items')->where('invoice_id', $invoice->id)->sum('amount'));
            }
        }

        if (Schema::hasTable('invoice_fee')) {
            $pivotCount = DB::table('invoice_fee')->where('invoice_id', $invoice->id)->count();

            if ($pivotCount > 0) {
                return $this->money(DB::table('invoice_fee')->where('invoice_id', $invoice->id)->sum('amount'));
            }
        }

        $total = $this->money($invoice->total_amount ?? 0);
        $discount = $this->money($invoice->discount_amount ?? 0);

        // Older rows did not persist a subtotal. A non-negative discount is
        // reliably reversible; otherwise preserve total_amount unchanged as
        // the conservative, non-destructive fallback.
        if (bccomp($total, '0.00', 2) >= 0 && bccomp($discount, '0.00', 2) >= 0) {
            return bcadd($total, $discount, 2);
        }

        return $total;
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 2);
    }
};
