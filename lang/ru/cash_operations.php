<?php

return [
    'title' => 'Операции с кассой',
    'subtitle' => 'Операционная касса, касса владельца, банк и InstaPay',
    'current_balance' => 'Текущий остаток',
    'today_in' => 'Приход сегодня',
    'today_out' => 'Расход сегодня',
    'recent_transfers' => 'Последние переводы',
    'no_transfers' => 'Переводов пока нет.',
    'no_accounts' => 'Касса этого типа ещё не создана.',

    'handover_action' => 'Передать выручку владельцу',
    'owner_return_action' => 'Пополнить операционную кассу',
    'generic_transfer_action' => 'Перевод между счетами',
    'open_shift_action' => 'Открыть смену',
    'close_shift_action' => 'Закрыть смену',
    // Finance Workspace UX corrective — this page is now the single
    // "Касса" landing reached from the sidebar; these two quick links
    // reuse the existing accounts list and sessions list pages instead of
    // giving them their own sidebar entries.
    'all_accounts_action' => 'Все кассовые счета',
    'sessions_action' => 'Кассовые смены',

    'handover_title' => 'Передача выручки владельцу',
    'handover_hint' => 'Часть операционной кассы физически передаётся владельцу школы. Это не расход и не новая выручка — только перемещение денег школы.',
    'owner_return_title' => 'Пополнение операционной кассы',
    'owner_return_hint' => 'Владелец возвращает часть кассы владельца в операционную кассу — например, для зарплат, закупок или возвратов. Это не доход и не расход.',

    'from_account' => 'Из кассы',
    'to_account' => 'В кассу',
    'available_now' => 'Доступно сейчас',
    'amount_to_transfer' => 'Сумма',
    'retained_amount' => 'Останется в кассе после операции',

    'from' => 'От',
    'to' => 'К',
    'transfer_type' => [
        'internal' => 'Перевод',
        'handover' => 'Передача владельцу',
        'owner_return' => 'Пополнение от владельца',
    ],
];
