<?php

use App\Models\TimetableSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 Phase 2: single-row settings table (same shape as
 * `finance_policy_settings` / FinancePolicySetting::current()) for the
 * timetable engine's weekly non-working days. Seeded here with the
 * approved default (Friday + Saturday) rather than hard-coding it inside
 * App\Support\WorkingDays or TimetableGrid — a future School Settings /
 * Academic Calendar module only needs to update this one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_settings', function (Blueprint $table) {
            $table->id();
            $table->json('non_working_days');
            $table->timestamps();
        });

        TimetableSetting::create([
            'id' => 1,
            'non_working_days' => ['fri', 'sat'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_settings');
    }
};
