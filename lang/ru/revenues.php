<?php

return [
    'section_details' => 'Данные дохода',
    'section_notes' => 'Описание и вложения',

    'reference_number' => 'Номер дохода',
    'category' => 'Категория',
    'category_name' => 'Название категории',
    'category_code' => 'Код категории',
    'category_code_help' => 'Стабильный идентификатор для логики интерфейса (например, fine, donation). Не меняется при переименовании категории.',
    'entries_count' => 'Записей',
    'payer_name' => 'Плательщик / источник',
    'student' => 'Ученик',
    'amount' => 'Сумма',
    'revenue_date' => 'Дата дохода',
    'cash_account' => 'Касса / счёт',
    'payment_method' => 'Способ оплаты',
    'method_cash' => 'Наличные',
    'method_card' => 'Карта',
    'method_bank' => 'Банковский перевод',
    'method_transfer' => 'Перевод',
    'description' => 'Назначение / описание',
    'notes' => 'Примечания',
    'attachment' => 'Квитанция / документ',
    'is_active' => 'Активна',

    'status' => 'Статус',
    'status_draft' => 'Черновик',
    'status_posted' => 'Проведён',
    'status_reversed' => 'Сторнирован',
    'status_help' => 'Черновик не влияет на кассу. При выборе «Проведён» доход сразу зачисляется на кассу/счёт.',

    'created_by' => 'Создал',
    'total' => 'Итого',
    'date_from' => 'С даты',
    'date_until' => 'По дату',

    'action_post' => 'Провести',
    'action_reverse' => 'Сторнировать',
    'reversal_reason' => 'Причина сторно',

    'posted_notification' => 'Доход проведён.',
    'reversed_notification' => 'Доход сторнирован.',
    'created_notification' => 'Доход создан.',
    'deleted_notification' => 'Черновик удалён.',

    'nav_revenues' => 'Прочие доходы',
    'nav_categories' => 'Категории доходов',

    // Dashboard-native pages (Non-Tuition Revenues V1 integration into
    // Финансы → Приход — no standalone sidebar entry, reached only from
    // the Приход type selector, mirroring Expenses V1's own dashboard-
    // native pattern).
    'page_title' => 'Прочие поступления',
    'donation_page_title' => 'Пожертвование',
    'other_page_title' => 'Прочий приход',
    'list_hint' => 'Поступления, не связанные с оплатой обучения учеников',
    'create' => 'Новое поступление',
    'show_title' => 'Поступление',
    'back' => 'Назад',
    'save' => 'Сохранить',
    'cancel' => 'Отмена',
    'actions' => 'Действия',
    'view' => 'Просмотр',
    'no_data' => 'Поступления не найдены.',
    'validation_error' => 'Проверьте правильность заполнения формы.',
    'attachment_download' => 'Скачать вложение',
    'attachment_none' => 'Без вложения',
    'ledger_transaction' => 'Кассовая операция',
    'ledger_none' => 'Доход ещё не отражён в кассе.',
    'delete_confirm' => 'Удалить черновик?',
    'delete' => 'Удалить',
];
