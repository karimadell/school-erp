<?php

return [
    // Finance landing page corrective — Финансы is now a compact, purely
    // presentational home page (summary + 4 actions + recent activity);
    // student search/billing moved under Приход (income.students).
    'page_title' => 'Финансы',
    'page_subtitle' => 'Доходы, расходы и касса школы',
    'recent_operations' => 'Последние операции',
    'no_recent_operations' => 'Операций пока нет.',
    'income_students_title' => 'Оплата ученика / Услуги',
    'income_students_hint' => 'Найдите ученика, чтобы принять оплату или выставить счёт за услугу.',

    // Top operational summary cards (Finance Workspace UX corrective).
    'income_today' => 'Приход сегодня',
    'expense_today' => 'Расход сегодня',
    'net_today' => 'Чистый поток сегодня',
    'total_balance' => 'Общий остаток',

    // Primary actions.
    'add_income' => 'Приход',
    'add_expense' => 'Расход',

    // Secondary quick links.
    'cash' => 'Касса',
    'reports' => 'Отчёты',

    // Приход — type selection screen.
    'income_type_title' => 'Тип поступления',
    'income_type_hint' => 'Выберите, какие деньги поступили — форма подберётся автоматически.',
    'income_type_registration' => 'Новый ученик / Регистрация',
    'income_type_registration_hint' => 'Зачисление нового ученика с оформлением счёта.',
    'income_type_payment' => 'Оплата ученика',
    'income_type_payment_hint' => 'Приём оплаты по существующему счёту ученика.',
    'income_type_service' => 'Услуга / дополнительный сбор',
    'income_type_service_hint' => 'Начисление и оплата дополнительной услуги ученику.',
    'income_type_donation' => 'Пожертвование',
    'income_type_donation_hint' => 'Благотворительное поступление, не связанное с учеником.',
    'income_type_other' => 'Прочий приход',
    'income_type_other_hint' => 'Любое иное поступление денежных средств.',

    'income_placeholder_title' => 'Раздел скоро будет доступен',
    'income_placeholder_body' => 'Учёт прочих поступлений (не связанных напрямую с учеником) переносится в отдельный модуль учёта доходов, который проходит финальную проверку перед подключением к рабочей системе. Пока этот раздел недоступен, обратитесь к администратору.',
    'income_placeholder_back' => 'Назад к выбору типа поступления',
];
