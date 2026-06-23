<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'nationality')) {
                $table->string('nationality')->nullable();
            }

            if (!Schema::hasColumn('students', 'photo')) {
                $table->string('photo')->nullable();
            }

            if (!Schema::hasColumn('students', 'documents')) {
                $table->json('documents')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['nationality', 'photo', 'documents'] as $column) {
                if (Schema::hasColumn('students', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};