<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_service_subscriptions', function (Blueprint $table): void {
            $table->dropUnique('student_service_subscriptions_enrollment_id_fee_id_unique');
        });
        Schema::create('student_service_subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('student_service_subscriptions')->cascadeOnDelete();
            $table->string('event_type');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subscription_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_service_subscription_events');
        Schema::table('student_service_subscriptions', function (Blueprint $table): void {
            $table->unique(['enrollment_id', 'fee_id']);
        });
    }
};
