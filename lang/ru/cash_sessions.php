<?php

// Phase 3 — Cash-drawer sessions (кассовые смены). Russian is authoritative.

return [
    'title' => 'Кассовые смены',
    'history' => 'История кассовых смен',
    'session' => 'Кассовая смена',
    'session_number' => 'Смена №:id',

    // Actions
    'open_session' => 'Открыть смену',
    'close_session' => 'Закрыть смену',
    'back' => 'Назад',
    'open' => 'Открыть',
    'close' => 'Закрыть',

    // Entities
    'drawer' => 'Касса',
    'cashier' => 'Кассир',
    'opened_by' => 'Открыл',
    'closed_by' => 'Закрыл',
    'opened_at' => 'Открыта',
    'closed_at' => 'Закрыта',

    // Balances
    'opening_balance' => 'Остаток на начало',
    'income' => 'Приход',
    'outflow' => 'Расход',
    'expected_balance' => 'Ожидаемый остаток',
    'actual_balance' => 'Фактический остаток',
    'variance' => 'Расхождение',
    'shortage' => 'Недостача',
    'overage' => 'Излишек',
    'no_variance' => 'Без расхождений',
    'variance_reason' => 'Причина расхождения',

    // Status
    'status' => 'Статус',
    'status_open' => 'Смена открыта',
    'status_closed' => 'Смена закрыта',

    // Opening baseline provenance
    'opening_source' => 'Источник остатка на начало',
    'source_previous_session' => 'Из предыдущей закрытой смены',
    'source_account_balance' => 'Из баланса кассы',
    'source_override' => 'Ручная корректировка',

    // Open form
    'open_title' => 'Открытие кассовой смены',
    'select_account' => 'Выберите кассу',
    'open_note' => 'Примечание к открытию',
    'open_note_placeholder' => 'Необязательно',
    'no_available_drawers' => 'Нет доступных наличных касс для открытия смены.',
    'already_open' => 'Смена уже открыта',

    // Show / activity
    'activity' => 'Движение по смене',
    'transactions' => 'Операции',
    'no_transactions' => 'По смене ещё нет операций.',
    'transaction_type_in' => 'Приход',
    'transaction_type_out' => 'Расход',
    'amount' => 'Сумма',
    'description' => 'Описание',
    'date' => 'Дата',
    'reconciliation' => 'Сверка кассы',

    // Close form
    'close_title' => 'Закрытие кассовой смены',
    'counted_total' => 'Фактический остаток',
    'counted_total_hint' => 'Пересчитайте наличные в кассе и введите итоговую сумму.',
    'close_note' => 'Причина расхождения',
    'close_note_hint' => 'Обязательно, если фактический остаток не совпадает с ожидаемым.',
    'variance_warning' => 'Расхождение будет зафиксировано как недостача или излишек.',
    'cannot_close_with_variance' => 'У вас нет прав закрывать смену с расхождением.',

    // Index
    'active_session' => 'Открытая смена',
    'no_active_session' => 'Нет открытой смены',
    'view' => 'Открыть',
    'no_history' => 'Кассовых смен пока нет.',

    // Flash
    'opened_success' => 'Смена открыта.',
    'closed_success' => 'Смена закрыта.',
];
