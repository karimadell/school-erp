<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->string('vehicle_code')->nullable()->unique()->after('id');
            $table->string('name')->nullable()->after('vehicle_code');
            $table->unsignedSmallInteger('student_capacity')->default(14)->after('capacity');
            $table->unsignedSmallInteger('passenger_capacity')->default(15)->after('student_capacity');
        });

        Schema::table('transport_routes', function (Blueprint $table) {
            $table->string('pricing_zone')->nullable()->after('name');
            $table->text('description')->nullable()->after('pricing_zone');
            $table->boolean('is_active')->default(true)->after('capacity');
        });

        Schema::create('student_transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('transport_route_id')->constrained('transport_routes')->restrictOnDelete();
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->string('pricing_zone')->nullable();
            $table->string('pickup_point')->nullable();
            $table->string('billing_period')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'status', 'effective_from', 'effective_to'], 'student_transport_enrollment_period_idx');
            $table->index(['bus_id', 'status', 'effective_from', 'effective_to'], 'student_transport_bus_capacity_idx');
            $table->index(['transport_route_id', 'status'], 'student_transport_route_status_idx');
            $table->index(['effective_from', 'effective_to'], 'student_transport_effective_dates_idx');
        });

        Schema::create('vehicle_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('weekdays')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->index(['bus_id', 'role', 'effective_from', 'effective_to'], 'vehicle_staff_bus_role_period_idx');
            $table->index(['user_id', 'effective_from', 'effective_to'], 'vehicle_staff_user_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_staff_assignments');
        Schema::dropIfExists('student_transport_assignments');
        Schema::table('transport_routes', fn (Blueprint $table) => $table->dropColumn(['pricing_zone', 'description', 'is_active']));
        Schema::table('buses', function (Blueprint $table) {
            $table->dropUnique(['vehicle_code']);
            $table->dropColumn(['vehicle_code', 'name', 'student_capacity', 'passenger_capacity']);
        });
    }
};
