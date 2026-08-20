<?php

// Minimal file: only the keys the Attendance module's own views reference
// (take.blade.php's period-column header). The Timetable module's own
// full key set (matching resources/lang/ru/timetable.php) is out of
// scope here and belongs to that module's own localization work.
return [
    'lesson' => 'الحصة',
    'generation_no_active_year' => 'لا توجد سنة دراسية نشطة. تم الاحتفاظ بالجدول السابق.',
    'generation_inactive_class' => 'لا يمكن إنشاء جدول لفصل غير نشط أو غير صالح.',
    'generation_no_curriculum' => 'لا توجد خطة دراسية نشطة للفصل. تم الاحتفاظ بالجدول السابق.',
    'generation_inactive_subject' => 'تحتوي الخطة الدراسية على مادة غير نشطة. تم الاحتفاظ بالجدول السابق.',
    'generation_missing_teacher' => 'لا يوجد معلم نشط ومكلّف لمادة واحدة أو أكثر.',
    'generation_insufficient_slots' => 'لا توجد حصص متاحة كافية لتنفيذ الخطة الأسبوعية المطلوبة.',
    'generation_incomplete' => 'تعذر إنشاء جدول كامل. تم الاحتفاظ بالجدول السابق.',
    'school_wide_title' => 'جدول المدرسة',
    'school_wide_navigation' => 'جدول المدرسة',
    'filter_classes' => 'الفصول',
    'all_classes' => 'كل الفصول',
    'apply_filter' => 'تطبيق',
    'no_lessons_yet' => 'لم تتم جدولة أي حصص بعد',
    'teacher_conflict_badge' => 'تعارض المعلم',
    'class_conflict_badge' => 'تعارض الفصل',
    'duplicate_cell_badge' => 'سجل مكرر',
    'empty_slot' => 'لا توجد حصة',
    'no_active_classes' => 'لا توجد فصول نشطة للعرض.',
    'open_class_timetable' => 'فتح جدول الفصل',
];
