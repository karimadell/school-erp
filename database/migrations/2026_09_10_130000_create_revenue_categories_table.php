<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-Tuition Revenues V1 — admin-manageable revenue categories, matching
 * this project's existing code+name lookup-table pattern (see
 * enrollment_modes): a stable `code` for programmatic/UI behavior, mutable
 * `name_ru`/`name_ar`/`name_en` for display. name_ar/name_en stay nullable
 * and unpopulated in this RU-first V1 — the schema is EN/AR-ready without a
 * future migration, but no translation batch is done in this pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_categories');
    }
};
