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
    'income_type_buffet' => 'Буфет',
    'income_type_buffet_hint' => 'Сдача выручки за день от школьного буфета.',
    'income_type_stolovaya' => 'Столовая',
    'income_type_stolovaya_hint' => 'Разовое питание ученика — начисление на конкретный день.',
    'income_type_other' => 'Прочий приход',
    'income_type_other_hint' => 'Любое иное поступление денежных средств.',

    'income_placeholder_title' => 'Раздел скоро будет доступен',
    'income_placeholder_body' => 'Учёт прочих поступлений (не связанных напрямую с учеником) переносится в отдельный модуль учёта доходов, который проходит финальную проверку перед подключением к рабочей системе. Пока этот раздел недоступен, обратитесь к администратору.',
    'income_placeholder_back' => 'Назад к выбору типа поступления',

    // Приход — bottom utility link into the existing service/fee catalog
    // (dashboard.finance.services.index), never a new pricing engine.
    'income_service_settings' => 'Настройка услуг и сборов',

    // Finance Workspace corrective PR #3 — unified "Добавить услугу" service picker.
    'add_service_title' => 'Добавить услугу',
    'add_service_hint' => 'Выберите услугу, которую нужно начислить ученику — форма подберётся автоматически.',
    'add_service_tuition' => 'Обучение',
    'add_service_tuition_hint' => 'Плата за обучение.',
    'add_service_transport' => 'Трансфер',
    'add_service_transport_hint' => 'Школьный транспорт.',
    'add_service_food' => 'Питание',
    'add_service_food_hint' => 'Разовое, недельное или произвольное питание с учётом учебного календаря.',
    'add_service_uniform' => 'Школьная форма',
    'add_service_uniform_hint' => 'Предметы школьной формы.',
    'add_service_extra_classes' => 'Дополнительные занятия',
    'add_service_extra_classes_hint' => 'Кружки и дополнительные занятия.',
    'add_service_activity' => 'Мероприятия и поездки',
    'add_service_activity_hint' => 'Экскурсии, мероприятия и поездки.',
    'add_service_other' => 'Прочие услуги',
    'add_service_other_hint' => 'Учебные материалы и другие услуги ученику.',

    // Столовая (Student, Phase 1) — dedicated daily-meal screen.
    'stolovaya_page_title' => 'Столовая',
    'stolovaya_page_hint' => 'Разовое питание — начисление за конкретный день.',
    'stolovaya_type_label' => 'Тип',
    'stolovaya_type_student' => 'Ученик',
    'stolovaya_date_label' => 'Дата',
    'stolovaya_meal_label' => 'Питание',
    'stolovaya_meal_placeholder' => 'Выберите питание',
    'stolovaya_quantity_label' => 'Количество',
    'stolovaya_unit_price_label' => 'Цена',
    'stolovaya_total_label' => 'Итого',
    'stolovaya_settlement_label' => 'Оплата',
    'stolovaya_settlement_paid_now' => 'Оплатить сейчас',
    'stolovaya_settlement_unpaid' => 'Не оплачивать (в долг)',
    'stolovaya_no_food_fee' => 'Услуга «Питание» не настроена — обратитесь к администратору.',
    'stolovaya_row_action' => 'Столовая',

    // Столовая (Employee, Phase 2) — dedicated employee cash-purchase
    // screen, separate from the Student one above.
    'stolovaya_employee_card_label' => 'Столовая (сотрудник)',
    'stolovaya_employee_card_hint' => 'Разовое питание сотрудника — оплата наличными.',
    'stolovaya_employee_page_title' => 'Столовая — сотрудник',
    'stolovaya_employee_type_label' => 'Сотрудник',
    'stolovaya_employee_placeholder' => 'Выберите сотрудника',
    'stolovaya_employee_cash_only_hint' => 'В этой фазе питание сотрудника оформляется только наличными.',
    'stolovaya_employee_submit' => 'Оформить и принять оплату',
    'stolovaya_employee_no_employees' => 'Нет доступных сотрудников с активной ролью.',
    'stolovaya_employee_receipt_title' => 'Питание сотрудника оформлено',
    'stolovaya_employee_receipt_employee' => 'Сотрудник',
    'stolovaya_employee_receipt_revenue_ref' => 'Номер записи о доходе',
];
