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

        DB::statement("
            UPDATE attendances
            SET attendance_key = CONCAT(
                COALESCE(type, 'daily'),
                '-',
                enrollment_id,
                '-',
                DATE_FORMAT(date, '%Y-%m-%d'),
                IF(period_id IS NULL, '', CONCAT('-', period_id))
            )
            WHERE attendance_key IS NULL
        ");

        // ✅ الحل هنا: نتأكد إن الـ index مش موجود قبل ما نضيفه
        $indexExists = collect(DB::select("
            SHOW INDEX FROM attendances 
            WHERE Key_name = 'attendances_attendance_key_unique'
        "))->isNotEmpty();

        if (!$indexExists) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unique('attendance_key', 'attendances_attendance_key_unique');
            });
        }
    }

    public function down(): void
    {
        // نحذف index لو موجود
        $indexExists = collect(DB::select("
            SHOW INDEX FROM attendances 
            WHERE Key_name = 'attendances_attendance_key_unique'
        "))->isNotEmpty();

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