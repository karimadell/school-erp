<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table): void {
            $table->foreignId('transport_route_id')
                ->nullable()
                ->after('passenger_capacity')
                ->constrained('transport_routes')
                ->restrictOnDelete();

            $table->index('transport_route_id', 'buses_transport_route_idx');
        });
    }

    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table): void {
            $table->dropForeign(['transport_route_id']);
            $table->dropIndex('buses_transport_route_idx');
            $table->dropColumn('transport_route_id');
        });
    }
};
