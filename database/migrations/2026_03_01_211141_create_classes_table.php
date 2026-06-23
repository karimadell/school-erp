<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {

            $table->id();

            $table->foreignId('grade_id')
                ->constrained('grades')
                ->cascadeOnDelete();

            // مثال: A , B , C
            $table->string('code');

            // عربي
            $table->string('name_ar');

            // روسي
            $table->string('name_ru')->nullable();

            // عدد الطلاب
            $table->integer('capacity')->default(30);

            // هل الفصل مفعل
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // يمنع تكرار نفس الفصل داخل نفس الصف
            $table->unique(['grade_id', 'code']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};