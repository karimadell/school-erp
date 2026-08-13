<?php

return [
    'title' => 'حصص الجدول الدراسي',
    'fields' => [
        'weekday' => 'يوم الأسبوع', 'bell_schedule' => 'جدول الجرس', 'period' => 'الحصة',
        'class' => 'الصف / المجموعة', 'subject' => 'المادة', 'teacher' => 'المعلم',
        'classroom' => 'الغرفة',
    ],
    'filters' => ['weekday' => 'يوم الأسبوع', 'class' => 'الصف / المجموعة', 'teacher' => 'المعلم'],
    'weekdays' => [1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد'],
    'validation' => [
        'draft_only' => 'لا يمكن تعديل حصص الجدول إلا في إصدار مسودة.',
        'required_reference' => 'هذا المرجع مطلوب.',
        'invalid_weekday' => 'يجب أن يكون يوم الأسبوع بين 1 و7.',
        'header_version_mismatch' => 'رأس الجدول ينتمي إلى إصدار آخر.',
        'cross_year_schedule' => 'جدول الجرس ينتمي إلى عام دراسي آخر.',
        'period_schedule_mismatch' => 'الحصة لا تنتمي إلى جدول الجرس المحدد.',
        'cross_year_classroom' => 'الغرفة تنتمي إلى عام دراسي آخر.',
        'inactive_classroom' => 'الغرفة المحددة غير نشطة.',
        'cross_year_curriculum' => 'المنهج ينتمي إلى عام دراسي آخر.',
        'subject_curriculum_mismatch' => 'المادة لا تطابق المنهج المحدد.',
        'class_curriculum_mismatch' => 'مرحلة الصف لا تطابق مرحلة المنهج.',
        'cross_year_assignment' => 'تكليف المعلم ينتمي إلى عام دراسي آخر.',
        'assignment_mismatch' => 'تكليف المعلم لا يطابق الصف والمادة المحددين.',
        'duplicate_entry' => 'هذه الحصة موجودة بالفعل في الوقت المحدد.',
        'teacher_conflict' => 'لدى المعلم حصة أخرى في هذا الوقت.',
        'class_conflict' => 'لدى الصف حصة أخرى في هذا الوقت.',
        'classroom_conflict' => 'الغرفة مشغولة في هذا الوقت.',
    ],
];
