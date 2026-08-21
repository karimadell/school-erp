<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type');
            $table->string('full_name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->char('citizenship_code', 2)->nullable();
            $table->text('residential_address')->nullable();
            $table->string('snils', 11)->nullable();
            $table->string('inn', 12)->nullable();
            $table->boolean('is_legal_representative')->default(true);
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('has_guardianship_authority')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'relationship_type']);
        });

        Schema::create('student_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('relationship')->nullable();
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->unsignedInteger('priority')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'priority']);
        });

        Schema::create('student_educational_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('has_ovz')->nullable();
            $table->boolean('has_disability')->nullable();
            $table->boolean('requires_adapted_program')->nullable();
            $table->boolean('requires_special_conditions')->nullable();
            $table->text('special_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->string('consent_status')->nullable();
            $table->date('consent_received_at')->nullable();
            $table->timestamps();
        });

        DB::table('students')->whereNotNull('documents')->orderBy('id')->chunkById(200, function ($students): void {
            foreach ($students as $student) {
                $legacy = json_decode($student->documents, true);
                if (! is_array($legacy)) {
                    continue;
                }
                foreach (['father', 'mother'] as $type) {
                    $contact = $legacy[$type] ?? null;
                    if (! is_array($contact) || ! filled($contact['name'] ?? null)) {
                        continue;
                    }
                    $exists = DB::table('student_representatives')->where('student_id', $student->id)->where('relationship_type', $type)->where('full_name', trim($contact['name']))->exists();
                    if (! $exists) {
                        DB::table('student_representatives')->insert([
                            'student_id' => $student->id, 'relationship_type' => $type, 'full_name' => trim($contact['name']),
                            'phone' => $contact['phone'] ?? null, 'email' => $contact['email'] ?? null,
                            'is_legal_representative' => true, 'is_primary_contact' => false, 'has_guardianship_authority' => false,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                $emergency = $legacy['emergency'] ?? null;
                if (is_array($emergency) && filled($emergency['name'] ?? null) && filled($emergency['phone'] ?? null)) {
                    $exists = DB::table('student_emergency_contacts')->where('student_id', $student->id)->where('full_name', trim($emergency['name']))->where('phone', $emergency['phone'])->exists();
                    if (! $exists) {
                        DB::table('student_emergency_contacts')->insert([
                            'student_id' => $student->id, 'full_name' => trim($emergency['name']), 'relationship' => $emergency['relationship'] ?? null,
                            'phone' => $emergency['phone'], 'priority' => 1, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_educational_needs');
        Schema::dropIfExists('student_emergency_contacts');
        Schema::dropIfExists('student_representatives');
    }
};
