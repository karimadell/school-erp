<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_members')) {
            Schema::create('staff_members', function (Blueprint $table): void {
                $table->id();
                $table->string('display_name');
                $table->string('phone')->nullable();
                $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('vehicle_staff_assignments', 'staff_member_id')) {
            Schema::table('vehicle_staff_assignments', function (Blueprint $table): void {
                $table->foreignId('staff_member_id')->nullable()->after('user_id')->constrained('staff_members')->restrictOnDelete();
            });
        }
        Schema::table('vehicle_staff_assignments', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        if (! Schema::hasTable('staff_transport_bootstrap_imports')) {
            Schema::create('staff_transport_bootstrap_imports', function (Blueprint $table): void {
                $table->id();
                $table->char('source_key', 64);
                $table->string('source_file');
                $table->string('source_sheet');
                $table->unsignedInteger('source_row');
                $table->string('raw_display_name');
                $table->text('raw_pickup_point')->nullable();
                $table->text('raw_contact')->nullable();
                $table->text('raw_notes')->nullable();
                $table->string('route');
                $table->foreignId('staff_member_id')->constrained('staff_members')->restrictOnDelete();
                $table->unsignedBigInteger('vehicle_staff_assignment_id')->nullable();
                $table->timestamps();
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vehicle_staff_assignments ADD CONSTRAINT vehicle_staff_identity_check CHECK ((user_id IS NOT NULL) <> (staff_member_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_transport_bootstrap_imports');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vehicle_staff_assignments DROP CHECK vehicle_staff_identity_check');
        }
        Schema::table('vehicle_staff_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_member_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
        Schema::dropIfExists('staff_members');
    }
};
