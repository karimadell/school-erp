<?php

return [
    'title' => 'الفوترة الجماعية',
    'subtitle' => 'إصدار فاتورة لخدمة واحدة لمجموعة من الطلاب لسنة دراسية.',
    'list_title' => 'دفعات الفوترة',
    'create_title' => 'دفعة فوترة جديدة',
    'edit_title' => 'تعديل الدفعة',
    'show_title' => 'دفعة فوترة',
    'preview_title' => 'معاينة',
    'preview_hint' => 'المبالغ للعلم فقط. لا يتم إنشاء فواتير في هذه الخطوة.',
    'preview_recheck_note' => 'قبل إنشاء الفواتير، تتم إعادة التحقق من البيانات وأحقية الفوترة والتعريفات.',

    'context' => [
        'create' => 'إنشاء',
        'edit' => 'تعديل',
    ],

    'create_action_note' => 'لا يتم إنشاء فواتير في هذه الخطوة. بعد ذلك ستظهر لك المعاينة الحسابية.',
    'required_legend' => 'الحقول المميزة بعلامة نجمة إلزامية.',

    'fields' => [
        'academic_year' => 'السنة الدراسية',
        'service' => 'الخدمة أو الرسوم',
        'quantity' => 'الكمية',
        'issue_date' => 'تاريخ الإصدار',
        'due_date' => 'تاريخ الاستحقاق',
        'description' => 'وصف الفاتورة',
        'target_mode' => 'من سيتم تضمينه',
        'target_mode_all' => 'جميع طلاب السنة',
        'target_mode_classes' => 'الفصول المختارة',
        'classes' => 'الفصول',
        'individual_students' => 'طلاب محددون',
        'include_students' => 'تضمين طلاب إضافيين',
        'exclude_students' => 'استبعاد طلاب',
    ],

    'search' => [
        'classes_placeholder' => 'بحث عن فصل…',
        'students_placeholder' => 'بحث عن طالب…',
        'no_classes' => 'لا توجد فصول متاحة.',
        'no_students' => 'لا يوجد طلاب متاحون.',
        'no_results' => 'لا توجد نتائج.',
        'selected_count' => 'المحدد: :count',
    ],

    'student_label' => ':name — :class',
    'student_no_class' => 'بدون فصل',

    'actions' => [
        'create' => 'إنشاء دفعة',
        'save' => 'حفظ',
        'save_and_review' => 'حفظ والانتقال إلى المعاينة',
        'update' => 'حفظ التغييرات',
        'preview' => 'حساب المعاينة',
        'execute' => 'إنشاء الفواتير',
        'edit' => 'تعديل',
        'back' => 'العودة إلى القائمة',
        'new' => 'دفعة جديدة',
    ],

    'execute_hint' => 'سيتم إعادة حساب جميع المبالغ على الخادم. لا يمكن التراجع عن ذلك.',

    'execute_errors' => [
        'already_processing' => 'الدفعة قيد التنفيذ بالفعل.',
        'already_completed' => 'تم إنشاء فواتير هذه الدفعة بالفعل.',
        'previously_failed' => 'فشلت الدفعة ولا يمكن تنفيذها مرة أخرى.',
        'not_previewed' => 'احسب المعاينة أولاً.',
        'execution_failed' => 'تعذّر إنشاء الفواتير. تم التراجع عن التنفيذ ولم يتم إنشاء أي فواتير.',
    ],

    'run' => [
        'title' => 'نتيجة التنفيذ',
        'no_run' => 'لم يتم تنفيذ الدفعة بعد.',
        'trigger' => 'نوع التشغيل',
        'trigger_manual' => 'يدوي',
        'executor' => 'المنفّذ',
        'executed_at' => 'وقت التنفيذ',
        'generated_count' => 'الفواتير المُنشأة',
        'skipped_count' => 'تم تخطّيها',
        'failed_count' => 'فشل',
        'total_generated' => 'الإجمالي المُنشأ',
        'failed_summary' => 'فشل التنفيذ. لم يتم إنشاء أي فواتير.',
        'invoice' => 'فاتورة',
        'view_invoice' => 'عرض الفاتورة',
        'status' => [
            'pending' => 'قيد الانتظار',
            'processing' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'failed' => 'فشل',
        ],
    ],

    'item_status' => [
        'pending' => 'قيد الانتظار',
        'generated' => 'تم إنشاء الفاتورة',
        'skipped' => 'تم تخطّيه',
        'failed' => 'فشل',
    ],

    'status' => [
        'draft' => 'مسودة',
        'previewed' => 'تم حساب المعاينة',
        'processing' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'failed' => 'فشل',
    ],

    'summary' => [
        'selected_count' => 'الطلاب المحددون',
        'eligible_count' => 'ستصدر لهم فواتير',
        'skipped_count' => 'تم تخطّيهم',
        'expected_invoice_count' => 'الفواتير المتوقعة',
        'expected_total_amount' => 'الإجمالي المتوقع',
    ],

    'config' => [
        'title' => 'معايير الفوترة',
        'selected_classes' => 'الفصول المحددة',
        'included_students' => 'طلاب مضمّنون إضافيون',
        'excluded_students' => 'الطلاب المستبعدون',
    ],

    'table' => [
        'student' => 'الطالب',
        'class' => 'الفصل',
        'service' => 'الخدمة',
        'unit_price' => 'سعر الوحدة',
        'quantity' => 'الكمية',
        'total' => 'الإجمالي',
        'eligibility' => 'الحالة',
        'reason' => 'سبب التخطي',
        'eligible' => 'ستصدر له فاتورة',
        'skipped' => 'تم تخطّيه',
    ],

    'flash' => [
        'created' => 'تم إنشاء دفعة الفوترة.',
        'updated' => 'تم تحديث دفعة الفوترة.',
        'previewed' => 'تم حساب المعاينة. لم يتم إنشاء أي فواتير.',
        'executed' => 'تم إنشاء الفواتير بنجاح.',
    ],

    'validation' => [
        'classes_required' => 'اختر فصلاً واحدًا على الأقل.',
        'due_after_issue' => 'لا يمكن أن يكون تاريخ الاستحقاق قبل تاريخ الإصدار.',
    ],

    'empty' => [
        'batches' => 'لم يتم إنشاء أي دفعات فوترة بعد.',
        'preview' => 'لم يتم حساب المعاينة بعد.',
    ],

    'skip_reasons' => [
        'year_inactive' => 'السنة الدراسية غير نشطة.',
        'service_inactive' => 'الخدمة معطّلة.',
        'no_enrollment' => 'لا يوجد تسجيل للسنة الدراسية المختارة.',
        'enrollment_inactive' => 'التسجيل غير نشط.',
        'enrollment_withdrawn' => 'انسحب الطالب.',
        'enrollment_graduated' => 'تخرّج الطالب.',
        'enrollment_transferred' => 'تم نقل الطالب.',
        'registration_duplicate' => 'تم احتساب رسوم التسجيل بالفعل.',
        'no_tariff' => 'لا يوجد تعريفة مهيأة للتاريخ المختار.',
        'pricing_error' => 'تعذّر حساب السعر.',
        'food_not_supported' => 'لا يدعم التحصيل الجماعي خدمة التغذية — استخدم التسجيل السريع بدلاً من ذلك.',
    ],
];
