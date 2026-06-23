<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_products', function (Blueprint $table) {
            $table->id();

            $table->string('name_ru');
            $table->string('name_ar')->nullable();

            // مثال: shirt, pants, jacket, shoes ...
            $table->string('category')->index();

            // مثال: 110, 116, S, M, L ...
            $table->string('size')->index();

            $table->decimal('price', 12, 2)->default(0);

            // لو مش عايز مخزون، سيبها null
            $table->integer('stock')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['category', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_products');
    }
};