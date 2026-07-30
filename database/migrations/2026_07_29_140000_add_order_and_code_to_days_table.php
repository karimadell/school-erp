<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 Phase 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, row D1): `days`
 * had neither an `order` column (despite `TimetableGrid::mount()` already
 * calling `Day::orderBy('order')`, a pre-existing broken query on an
 * empty table) nor any stable, non-localized identifier for "which
 * weekday is this row" — needed so weekly non-working days (App\Support\
 * WorkingDays) can be configured by a durable `code`, not by matching
 * against the free-text, localizable `name` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('days', function (Blueprint $table) {
            $table->unsignedTinyInteger('order')->nullable()->after('name');
            $table->string('code')->nullable()->unique()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('days', function (Blueprint $table) {
            $table->dropColumn(['order', 'code']);
        });
    }
};
