<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            DocumentType::firstOrCreate(['code' => $definition['code']], $definition);
        }
    }

    public static function definitions(): array
    {
        return [
            ['code' => 'birth_certificate', 'name_ru' => 'Свидетельство о рождении', 'name_en' => 'Birth certificate', 'name_ar' => 'شهادة الميلاد', 'holder_scope' => 'student', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => false, 'supports_expiration' => false, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 10],
            ['code' => 'passport', 'name_ru' => 'Паспорт', 'name_en' => 'Passport', 'name_ar' => 'جواز السفر', 'holder_scope' => 'either', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => true, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 20],
            ['code' => 'residence_permit', 'name_ru' => 'Вид на жительство', 'name_en' => 'Residence permit', 'name_ar' => 'تصريح الإقامة', 'holder_scope' => 'either', 'is_identity_document' => true, 'supports_series' => true, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => true, 'is_active' => true, 'sort_order' => 30],
            ['code' => 'previous_school', 'name_ru' => 'Документ из предыдущей школы', 'name_en' => 'Previous school document', 'name_ar' => 'وثيقة المدرسة السابقة', 'holder_scope' => 'student', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => false, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 40],
            ['code' => 'medical', 'name_ru' => 'Медицинский документ', 'name_en' => 'Medical document', 'name_ar' => 'وثيقة طبية', 'holder_scope' => 'student', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 50],
            ['code' => 'other', 'name_ru' => 'Другой документ', 'name_en' => 'Other document', 'name_ar' => 'وثيقة أخرى', 'holder_scope' => 'either', 'is_identity_document' => false, 'supports_series' => false, 'supports_subdivision_code' => false, 'supports_expiration' => true, 'requires_expiration' => false, 'is_active' => true, 'sort_order' => 100],
        ];
    }
}
