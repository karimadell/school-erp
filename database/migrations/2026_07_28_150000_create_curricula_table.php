<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 8 / C1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, Section 3):
 * Curriculum — Academic Year x Grade x Subject, the core new entity for
 * this section: weekly hours and mandatory/elective/optional-enrichment
 * type. Deliberately Grade-level only, no class_id — the Class-level
 * override mechanism is explicitly reserved and undesigned
 * (docs/OPEN_POLICY_DECISIONS.md, Section 3, "None approved"). Does not
 * implement ResolvesAcademicYear / the Item 2 historical-year lock —
 * that integration is explicitly deferred, a decision for whichever
 * later item actually needs it (C2/C3), not bundled in here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();

            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('weekly_hours');
            $table->enum('type', ['mandatory', 'elective', 'optional_enrichment']);

            $table->timestamps();

            $table->unique(
                ['academic_year_id', 'grade_id', 'subject_id'],
                'curricula_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};
