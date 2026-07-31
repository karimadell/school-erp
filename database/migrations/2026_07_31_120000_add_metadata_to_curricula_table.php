<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->enum('assessment_type', ['grade', 'pass_fail', 'ungraded'])
                ->default('grade');
            $table->unsignedSmallInteger('report_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->dropColumn([
                'assessment_type',
                'report_order',
                'is_active',
                'notes',
            ]);
        });
    }
};
