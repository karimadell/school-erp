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
];
