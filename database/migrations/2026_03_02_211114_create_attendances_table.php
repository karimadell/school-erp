<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('period_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->date('date');

            // نوع الحضور
            $table->enum('type', ['daily', 'period'])
                ->default('daily');

            $table->enum('status', ['present', 'absent', 'late', 'excused'])
                ->default('present');

            $table->text('note')->nullable();

            // 🔥 ده الحل الحقيقي
            $table->string('attendance_key')->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};