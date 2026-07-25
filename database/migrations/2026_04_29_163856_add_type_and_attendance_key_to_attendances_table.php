<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {

            if (!Schema::hasColumn('attendances', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('enrollment_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('attendances', 'type')) {
                $table->enum('type', ['daily', 'period'])
                    ->default('daily')
                    ->after('date');
            }

            if (!Schema::hasColumn('attendances', 'attendance_key')) {
                $table->string('attendance_key')
                    ->nullable()
                    ->after('note');
            }
        });

        // تحديث البيانات القديمة
        DB::table('attendances')
            ->whereNull('type')
            ->update(['type' => 'daily']);

        // Backfill attendance_key with database-neutral PHP logic so every driver
        // produces the same "{type}-{enrollment_id}-{date}[-{period_id}]" format
        // used by AttendanceController::store(). MySQL's CONCAT/IF/DATE_FORMAT
        // are not available on SQLite, and the date column is already stored as
        // a plain Y-m-d string on both drivers, so no formatting step is needed.
        DB::table('attendances')
            ->whereNull('attendance_key')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $type = $row->type ?: 'daily';
                    $key = "{$type}-{$row->enrollment_id}-{$row->date}";

                    if (! is_null($row->period_id)) {
                        $key .= "-{$row->period_id}";
                    }

                    DB::table('attendances')
                        ->where('id', $row->id)
                        ->update(['attendance_key' => $key]);
                }
            });

        // ✅ الحل هنا: نتأكد إن الـ index مش موجود قبل ما نضيفه
        // Schema::getIndexes() is driver-agnostic (information_schema on MySQL,
        // PRAGMA on SQLite), replacing the MySQL-only SHOW INDEX statement.
        $indexExists = collect(Schema::getIndexes('attendances'))
            ->contains('name', 'attendances_attendance_key_unique');

        if (!$indexExists) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unique('attendance_key', 'attendances_attendance_key_unique');
            });
        }
    }

    public function down(): void
    {
        // نحذف index لو موجود
        $indexExists = collect(Schema::getIndexes('attendances'))
            ->contains('name', 'attendances_attendance_key_unique');

        if ($indexExists) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropUnique('attendances_attendance_key_unique');
            });
        }

        Schema::table('attendances', function (Blueprint $table) {

            if (Schema::hasColumn('attendances', 'attendance_key')) {
                $table->dropColumn('attendance_key');
            }

            if (Schema::hasColumn('attendances', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('attendances', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });
    }
};