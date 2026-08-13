<?php

return [
    'navigation_group' => 'العملية التعليمية', 'navigation' => 'إصدارات الجدول الدراسي',
    'model' => 'إصدار الجدول الدراسي', 'models' => 'إصدارات الجدول الدراسي',
    'fields' => [
        'academic_year' => 'العام الدراسي', 'name' => 'الاسم', 'status' => 'الحالة',
        'effective_from' => 'ساري من', 'effective_to' => 'ساري حتى', 'notes' => 'ملاحظات',
        'published_by' => 'نشر بواسطة', 'published_at' => 'تاريخ النشر',
    ],
    'statuses' => ['draft' => 'مسودة', 'published' => 'منشور', 'archived' => 'مؤرشف'],
    'actions' => ['publish' => 'نشر'],
    'messages' => ['published' => 'تم نشر إصدار الجدول الدراسي.'],
    'validation' => [
        'published_immutable' => 'لا يمكن تعديل إصدار جدول دراسي منشور.',
        'invalid_status' => 'حالة إصدار الجدول الدراسي غير صالحة.',
        'invalid_dates' => 'يجب ألا يسبق تاريخ انتهاء السريان تاريخ بدايته.',
        'outside_academic_year' => 'يجب أن تقع تواريخ السريان ضمن العام الدراسي.',
        'publish_through_service' => 'انشر إصدارات الجدول الدراسي من خلال مسار النشر.',
        'only_draft_publishable' => 'يمكن نشر إصدار مسودة فقط.',
        'hard_delete_forbidden' => 'لا يمكن حذف إصدارات الجدول الدراسي لأنها سجلات تاريخية.',
        'invalid_weekday' => 'يجب أن يكون يوم الأسبوع بين 1 و7.',
        'header_version_mismatch' => 'يجب أن ينتمي رأس الجدول إلى إصدار سجل الحصة نفسه.',
        'cross_year_reference' => 'يجب أن ينتمي المرجع المحدد إلى العام الدراسي لإصدار الجدول.',
        'period_schedule_mismatch' => 'يجب أن تنتمي الحصة المحددة إلى جدول الجرس المحدد.',
    ],
];
