<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * academic_year_id and class_room_id were created NOT NULL with no
     * default, but EnrollmentController::store()/update() never populate
     * class_room_id (not even in the Enrollment model's $fillable — it's a
     * leftover from the superseded ClassRoom concept, replaced by class_id)
     * and treat academic_year_id as optional. Every enrollment insert
     * failed with a NOT NULL constraint violation as a result.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->change();
            $table->foreignId('class_room_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable(false)->change();
            $table->foreignId('class_room_id')->nullable(false)->change();
        });
    }
};
