<?php

return [
    'navigation_group' => 'العملية التعليمية',
    'navigation' => 'التقويم الأكاديمي',
    'model' => 'التقويم الأكاديمي',
    'models' => 'التقاويم الأكاديمية',
    'fields' => [
        'academic_year' => 'العام الدراسي', 'weekly_days_off' => 'العطلات الأسبوعية',
        'default_bell_schedule_id' => 'معرّف جدول الجرس الافتراضي', 'events' => 'الأحداث',
        'updated_at' => 'آخر تحديث', 'name' => 'الاسم', 'type' => 'النوع',
        'start_date' => 'تاريخ البداية', 'end_date' => 'تاريخ النهاية', 'effect' => 'التأثير',
        'bell_schedule_id' => 'معرّف جدول الجرس', 'shift' => 'الفترة', 'notes' => 'ملاحظات',
        'is_active' => 'نشط',
    ],
    'help' => ['default_bell_schedule_id' => 'يمكن تعبئته بعد تثبيت وحدة جداول الجرس.'],
    'weekdays' => [
        'sun' => 'الأحد', 'mon' => 'الاثنين', 'tue' => 'الثلاثاء', 'wed' => 'الأربعاء',
        'thu' => 'الخميس', 'fri' => 'الجمعة', 'sat' => 'السبت',
    ],
    'events' => ['title' => 'الأحداث والاستثناءات'],
    'types' => [
        'official_holiday' => 'عطلة رسمية', 'school_holiday' => 'عطلة مدرسية',
        'school_event' => 'فعالية مدرسية', 'teaching_override' => 'استثناء يوم دراسي',
        'bell_schedule_override' => 'استثناء جدول الجرس',
    ],
    'effects' => [
        'non_teaching' => 'يوم غير دراسي', 'teaching_day' => 'يوم دراسي',
        'shortened' => 'يوم دراسي مختصر',
    ],
    'validation' => [
        'active_academic_year_required' => 'لا يمكن إنشاء تقويم أكاديمي جديد إلا للعام الدراسي النشط.',
        'weekly_days_off' => 'اختر عطلة أسبوعية صحيحة واحدة على الأقل.',
        'invalid_event_type' => 'نوع حدث التقويم غير صالح.',
        'end_before_start' => 'لا يمكن أن يسبق تاريخ النهاية تاريخ البداية.',
        'event_outside_year' => 'يجب أن يقع الحدث ضمن العام الدراسي المحدد.',
        'teaching_override_effect' => 'حدد تأثير استثناء اليوم الدراسي.',
        'bell_schedule_required' => 'يلزم تحديد جدول جرس لاستثناء جدول الجرس.',
        'bell_override_single_day' => 'لا يمكن تطبيق استثناء جدول الجرس إلا على يوم واحد.',
    ],
];
