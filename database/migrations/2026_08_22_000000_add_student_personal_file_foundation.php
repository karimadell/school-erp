<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('patronymic')->nullable();
            $table->string('birth_place')->nullable();
            $table->char('citizenship_code', 2)->nullable()->index();
            $table->text('residential_address')->nullable();
            $table->text('registration_address')->nullable();
            $table->string('snils', 11)->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('registration_status')->nullable()->index();
            $table->timestamp('registration_submitted_at')->nullable();
            $table->timestamp('registration_completed_at')->nullable();
            $table->foreignId('registration_completed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('admission_context')->nullable()->index();
            $table->string('previous_school_name')->nullable();
            $table->char('previous_school_country_code', 2)->nullable();
            $table->string('previous_grade')->nullable();
            $table->string('previous_class')->nullable();
            $table->text('previous_education_notes')->nullable();
        });

        DB::table('students')->orderBy('id')->chunkById(200, function ($students): void {
            foreach ($students as $student) {
                $updates = [];
                if ($student->first_name === null && filled($student->first_name_ru)) {
                    $updates['first_name'] = $student->first_name_ru;
                }
                if ($student->last_name === null && filled($student->last_name_ru)) {
                    $updates['last_name'] = $student->last_name_ru;
                }
                if ($student->patronymic === null && filled($student->patronymic_ru)) {
                    $updates['patronymic'] = $student->patronymic_ru;
                }
                if ($student->residential_address === null && filled($student->address)) {
                    $updates['residential_address'] = $student->address;
                }
                if ($student->citizenship_code === null) {
                    $code = match (mb_strtolower(trim((string) $student->nationality))) {
                        'россия', 'российская федерация', 'russia', 'russian federation', 'ru', 'rus' => 'RU',
                        'египет', 'egypt', 'eg', 'egy' => 'EG',
                        default => null,
                    };
                    if ($code) {
                        $updates['citizenship_code'] = $code;
                    }
                }
                if ($student->registration_status === null) {
                    $updates['registration_status'] = match ($student->status) {
                        'registration_completed', 'active', 'suspended', 'graduated' => 'completed',
                        'documents_required' => 'documents_incomplete',
                        'under_review' => 'ready_for_review',
                        default => 'draft',
                    };
                }
                if ($updates !== []) {
                    DB::table('students')->where('id', $student->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex(['admission_context']);
            $table->dropColumn(['admission_context', 'previous_school_name', 'previous_school_country_code', 'previous_grade', 'previous_class', 'previous_education_notes']);
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_completed_by');
            $table->dropIndex(['citizenship_code']);
            $table->dropIndex(['registration_status']);
            $table->dropColumn(['first_name', 'last_name', 'patronymic', 'birth_place', 'citizenship_code', 'residential_address', 'registration_address', 'snils', 'inn', 'registration_status', 'registration_submitted_at', 'registration_completed_at']);
        });
    }
};
