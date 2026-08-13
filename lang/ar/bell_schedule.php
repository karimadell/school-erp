<?php

return [
    'navigation_group' => 'العملية التعليمية', 'navigation' => 'جداول الجرس',
    'model' => 'جدول الجرس', 'models' => 'جداول الجرس',
    'fields' => [
        'academic_year' => 'العام الدراسي', 'name' => 'الاسم', 'shift' => 'الفترة',
        'is_default' => 'افتراضي', 'is_active' => 'نشط', 'notes' => 'ملاحظات',
        'periods' => 'الحصص', 'period_number' => 'رقم الحصة', 'label' => 'التسمية',
        'starts_at' => 'وقت البداية', 'ends_at' => 'وقت النهاية',
        'break_after_minutes' => 'الاستراحة بعد الحصة بالدقائق',
    ],
    'help' => ['default' => 'يمكن أن يكون لكل فترة جدول افتراضي نشط واحد فقط في العام الدراسي.'],
    'periods' => ['title' => 'الحصص وأوقات الجرس'],
    'validation' => [
        'shift_min' => 'يجب ألا تقل الفترة عن 1.',
        'end_after_start' => 'يجب أن يكون وقت النهاية بعد وقت البداية.',
        'duplicate_period_number' => 'رقم الحصة مستخدم بالفعل في جدول الجرس.',
        'period_overlap' => 'تتداخل الحصة مع حصة أخرى في جدول الجرس.',
    ],
];
