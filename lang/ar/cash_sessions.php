<?php

// Phase 3 — Cash-drawer sessions. Matches lang/ru/cash_sessions.php keys.

return [
    'title' => 'ورديات الصندوق',
    'history' => 'سجل ورديات الصندوق',
    'session' => 'وردية الصندوق',
    'session_number' => 'وردية رقم :id',

    // Actions
    'open_session' => 'فتح وردية',
    'close_session' => 'إغلاق الوردية',
    'back' => 'رجوع',
    'open' => 'فتح',
    'close' => 'إغلاق',

    // Entities
    'drawer' => 'الصندوق',
    'cashier' => 'أمين الصندوق',
    'opened_by' => 'فتحها',
    'closed_by' => 'أغلقها',
    'opened_at' => 'وقت الفتح',
    'closed_at' => 'وقت الإغلاق',

    // Balances
    'opening_balance' => 'الرصيد الافتتاحي',
    'income' => 'الوارد',
    'outflow' => 'المنصرف',
    'expected_balance' => 'الرصيد المتوقع',
    'actual_balance' => 'الرصيد الفعلي',
    'variance' => 'الفرق',
    'shortage' => 'عجز',
    'overage' => 'زيادة',
    'no_variance' => 'لا يوجد فرق',
    'variance_reason' => 'سبب الفرق',

    // Status
    'status' => 'الحالة',
    'status_open' => 'الوردية مفتوحة',
    'status_closed' => 'الوردية مغلقة',

    // Opening baseline provenance
    'opening_source' => 'مصدر الرصيد الافتتاحي',
    'source_previous_session' => 'من الوردية المغلقة السابقة',
    'source_account_balance' => 'من رصيد الصندوق',
    'source_override' => 'تعديل يدوي',

    // Open form
    'open_title' => 'فتح وردية صندوق',
    'select_account' => 'اختر الصندوق',
    'open_note' => 'ملاحظة الفتح',
    'open_note_placeholder' => 'اختياري',
    'no_available_drawers' => 'لا توجد صناديق نقدية متاحة لفتح وردية.',
    'already_open' => 'يوجد وردية مفتوحة بالفعل',

    // Show / activity
    'activity' => 'حركة الوردية',
    'transactions' => 'العمليات',
    'no_transactions' => 'لا توجد عمليات في هذه الوردية بعد.',
    'transaction_type_in' => 'وارد',
    'transaction_type_out' => 'منصرف',
    'amount' => 'المبلغ',
    'description' => 'الوصف',
    'date' => 'التاريخ',
    'reconciliation' => 'تسوية الصندوق',

    // Close form
    'close_title' => 'إغلاق وردية الصندوق',
    'counted_total' => 'الرصيد الفعلي',
    'counted_total_hint' => 'أعد عدّ النقدية في الصندوق وأدخل الإجمالي.',
    'close_note' => 'سبب الفرق',
    'close_note_hint' => 'مطلوب عند اختلاف الرصيد الفعلي عن المتوقع.',
    'variance_warning' => 'سيُسجَّل الفرق كعجز أو زيادة.',
    'cannot_close_with_variance' => 'ليس لديك صلاحية إغلاق وردية بها فرق.',

    // Index
    'active_session' => 'وردية مفتوحة',
    'no_active_session' => 'لا توجد وردية مفتوحة',
    'view' => 'فتح',
    'no_history' => 'لا توجد ورديات صندوق بعد.',

    // Flash
    'opened_success' => 'تم فتح الوردية.',
    'closed_success' => 'تم إغلاق الوردية.',
];
