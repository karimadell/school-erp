<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('holder_scope')->default('student');
            $table->boolean('is_identity_document')->default(false);
            $table->boolean('supports_series')->default(false);
            $table->boolean('supports_subdivision_code')->default(false);
            $table->boolean('supports_expiration')->default(false);
            $table->boolean('requires_expiration')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('document_types')->insert([
            ['code' => 'birth_certificate', 'name_ru' => 'Свидетельство о рождении', 'name_en' => 'Birth certificate', 'name_ar' => 'شهادة الميلاد', 'holder_scope' => 'student', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => false, 'supports_expiration' => false, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'passport', 'name_ru' => 'Паспорт', 'name_en' => 'Passport', 'name_ar' => 'جواز السفر', 'holder_scope' => 'either', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => true, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'residence_permit', 'name_ru' => 'Вид на жительство', 'name_en' => 'Residence permit', 'name_ar' => 'تصريح الإقامة', 'holder_scope' => 'either', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => true, 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'previous_school', 'name_ru' => 'Документ из предыдущей школы', 'name_en' => 'Previous school document', 'name_ar' => 'وثيقة المدرسة السابقة', 'holder_scope' => 'student', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => false, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'medical', 'name_ru' => 'Медицинский документ', 'name_en' => 'Medical document', 'name_ar' => 'وثيقة طبية', 'holder_scope' => 'student', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'other', 'name_ru' => 'Другой документ', 'name_en' => 'Other document', 'name_ar' => 'وثيقة أخرى', 'holder_scope' => 'either', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 100, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('student_files', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_representative_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('series')->nullable();
            $table->string('document_number')->nullable()->index();
            $table->text('issued_by')->nullable();
            $table->string('subdivision_code')->nullable();
            $table->char('issuing_country_code', 2)->nullable();
            $table->string('verification_status')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
        });

        DB::table('student_files')->whereNull('document_type_id')->orderBy('id')->chunkById(200, function ($files): void {
            $types = DB::table('document_types')->pluck('id', 'code');
            foreach ($files as $file) {
                if (isset($types[$file->type])) {
                    DB::table('student_files')->where('id', $file->id)->update(['document_type_id' => $types[$file->type]]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
            $table->dropConstrainedForeignId('student_representative_id');
            $table->dropConstrainedForeignId('enrollment_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropIndex(['document_number']);
            $table->dropColumn(['series', 'document_number', 'issued_by', 'subdivision_code', 'issuing_country_code', 'verification_status', 'verified_at', 'metadata']);
        });
        Schema::dropIfExists('document_types');
    }
};
