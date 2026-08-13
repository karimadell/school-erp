<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->unique()->constrained()->restrictOnDelete();
            $table->json('weekly_days_off');
            // Intentionally not constrained until the bell-schedule foundation exists.
            $table->unsignedBigInteger('default_bell_schedule_id')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type');
            $table->string('effect')->nullable();
            // Kept ready for the independently scheduled bell/shift foundation.
            $table->unsignedBigInteger('bell_schedule_id')->nullable();
            $table->unsignedSmallInteger('shift')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['academic_calendar_id', 'start_date', 'end_date'], 'calendar_events_date_range_index');
            $table->index(['academic_calendar_id', 'type', 'is_active'], 'calendar_events_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('academic_calendars');
    }
};
