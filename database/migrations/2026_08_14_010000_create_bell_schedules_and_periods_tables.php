<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bell_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('shift')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            // Portable partial-unique equivalent: active defaults use 1;
            // every other row uses NULL (multiple NULLs remain permitted).
            $table->unsignedTinyInteger('default_slot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['academic_year_id', 'shift', 'is_active'], 'bell_schedules_year_shift_active_index');
            $table->unique(
                ['academic_year_id', 'shift', 'default_slot'],
                'bell_schedules_one_active_default_unique',
            );
        });

        Schema::create('bell_schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bell_schedule_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_number');
            $table->string('label')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_after_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bell_schedule_id', 'period_number'], 'bell_schedule_period_number_unique');
            $table->index(['bell_schedule_id', 'starts_at', 'ends_at'], 'bell_schedule_period_time_index');
        });

        // A1 intentionally stored unconstrained placeholder IDs. Since no target
        // table existed then, any non-null value that does not now resolve is an
        // orphan and cannot safely survive conversion to a real foreign key.
        DB::table('academic_calendars')
            ->whereNotNull('default_bell_schedule_id')
            ->whereNotIn('default_bell_schedule_id', DB::table('bell_schedules')->select('id'))
            ->update(['default_bell_schedule_id' => null]);
        DB::table('calendar_events')
            ->whereNotNull('bell_schedule_id')
            ->whereNotIn('bell_schedule_id', DB::table('bell_schedules')->select('id'))
            ->update(['bell_schedule_id' => null]);

        Schema::table('academic_calendars', function (Blueprint $table) {
            $table->foreign('default_bell_schedule_id', 'academic_calendars_default_bell_schedule_fk')
                ->references('id')->on('bell_schedules')->restrictOnDelete();
        });
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreign('bell_schedule_id', 'calendar_events_bell_schedule_fk')
                ->references('id')->on('bell_schedules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign('calendar_events_bell_schedule_fk');
        });
        Schema::table('academic_calendars', function (Blueprint $table) {
            $table->dropForeign('academic_calendars_default_bell_schedule_fk');
        });

        Schema::dropIfExists('bell_schedule_periods');
        Schema::dropIfExists('bell_schedules');
    }
};
