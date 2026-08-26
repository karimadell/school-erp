<?php

return [

    'index_title' => 'الخزائن النقدية',
    'add_new' => 'إضافة خزنة جديدة',
    'edit_title' => 'تعديل الخزنة',
    'edit_account' => 'تعديل الحساب',
    'create_title' => 'إضافة خزنة',
    'back' => 'رجوع',
    'save_account' => 'حفظ الخزنة',

    'name' => 'اسم الخزنة',
    'type' => 'نوع الخزنة',
    'type_main' => 'خزنة رئيسية',
    'type_sub' => 'خزنة فرعية',
    'type_cash' => 'نقدي',
    'type_bank' => 'حساب بنكي',
    'type_owner_cash' => 'خزنة المالك',
    'type_instapay' => 'InstaPay',
    'parent_account' => 'الخزنة الرئيسية',
    'select_parent' => 'اختر الخزنة الرئيسية',
    'opening_balance' => 'الرصيد الافتتاحي',
    'balance_readonly_hint' => 'لا يمكن تغيير الرصيد إلا من خلال حركات الخزنة.',

    'save_changes' => 'حفظ التعديلات',
    'cancel' => 'إلغاء',

    'created_success' => 'تم إنشاء الخزنة بنجاح.',
    'updated_success' => 'تم تحديث الخزنة بنجاح.',

    'column_type' => 'النوع',
    'column_balance' => 'الرصيد',
    'badge_main' => 'رئيسي',
    'badge_sub' => 'فرعي',
    'confirm_delete' => 'هل أنت متأكد من حذف هذا الحساب؟',

    'validation' => [
        'name_required' => 'اسم الخزنة مطلوب.',
        'type_required' => 'يرجى اختيار نوع خزنة صحيح.',
        'parent_invalid' => 'الخزنة الرئيسية المختارة غير صالحة.',
        'parent_circular' => 'هذا الاختيار سينشئ تسلسلاً هرميًا دائريًا للخزن.',
        'balance_numeric' => 'يجب أن يكون الرصيد رقمًا صحيحًا.',
    ],

];
