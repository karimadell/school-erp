<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5: a uniform bundle and its individual items are both represented
 * as ordinary Fee rows (category = uniform), each independently priced via
 * FeePrice (policy decision 1: bundle price is not derived from its
 * components). This table only records which items make up a bundle, for
 * fulfillment/packing purposes — it carries no pricing responsibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_bundle_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bundle_fee_id')->constrained('fees')->cascadeOnDelete();
            $table->foreignId('item_fee_id')->constrained('fees')->cascadeOnDelete();
            $table->integer('quantity')->default(1);

            $table->timestamps();

            $table->unique(['bundle_fee_id', 'item_fee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_bundle_components');
    }
};
