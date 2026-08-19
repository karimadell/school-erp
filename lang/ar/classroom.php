<?php

return [
    'navigation_group' => 'العملية التعليمية', 'navigation' => 'الغرف',
    'model' => 'غرفة', 'models' => 'الغرف',
    'search_placeholder' => 'البحث بالرمز أو الاسم أو المبنى…',
    'active' => 'نشط', 'inactive' => 'غير نشط',
    'title' => 'الغرف الدراسية', 'list_hint' => 'القاعات الفعلية وسعتها.',
    'create' => 'إضافة قاعة', 'edit' => 'تعديل القاعة',
    'back' => 'العودة إلى القائمة', 'save' => 'حفظ', 'cancel' => 'إلغاء',
    'actions' => 'إجراءات', 'no_data' => 'لا توجد قاعات.', 'filter' => 'تصفية',
    'all_academic_years' => 'كل الأعوام الدراسية', 'all_room_types' => 'كل أنواع القاعات',
    'created_success' => 'تم إنشاء القاعة.', 'updated_success' => 'تم تحديث القاعة.',
    'fields' => [
        'academic_year' => 'العام الدراسي', 'building' => 'المبنى', 'floor' => 'الطابق',
        'code' => 'رمز القاعة', 'name' => 'الاسم', 'capacity' => 'السعة',
        'room_type' => 'نوع القاعة', 'is_active' => 'نشط', 'notes' => 'ملاحظات',
    ],
    'types' => [
        'classroom' => 'قاعة دراسية', 'laboratory' => 'مختبر',
        'computer_lab' => 'مختبر حاسوب', 'science_lab' => 'مختبر علوم',
        'art_room' => 'قاعة فنون', 'music_room' => 'قاعة موسيقى', 'library' => 'مكتبة',
        'sports_hall' => 'صالة رياضية', 'auditorium' => 'قاعة محاضرات',
        'exam_room' => 'قاعة امتحانات', 'meeting_room' => 'غرفة اجتماعات', 'other' => 'أخرى',
    ],
    'validation' => [
        'capacity_min' => 'يجب ألا تقل السعة عن 1.',
        'invalid_room_type' => 'نوع القاعة المحدد غير صالح.',
        'duplicate_code' => 'توجد قاعة بهذا الرمز في العام الدراسي نفسه.',
        'hard_delete_forbidden' => 'لا يمكن حذف القاعة. قم بإلغاء تنشيطها للحفاظ على السجل.',
    ],
];
