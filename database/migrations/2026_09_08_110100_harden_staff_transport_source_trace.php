<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_transport_bootstrap_imports', function (Blueprint $table): void {
            $table->unique('source_key', 'staff_transport_source_key_unique');
            $table->foreign('vehicle_staff_assignment_id', 'staff_import_assignment_fk')
                ->references('id')->on('vehicle_staff_assignments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_transport_bootstrap_imports', function (Blueprint $table): void {
            $table->dropForeign('staff_import_assignment_fk');
            $table->dropUnique('staff_transport_source_key_unique');
        });
    }
};
