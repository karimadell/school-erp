<?php

return [

    'index_title' => 'Кассовые счета',
    'add_new' => 'Добавить новую кассу',
    'edit_title' => 'Редактирование кассы',
    'edit_account' => 'Редактировать кассу',
    'create_title' => 'Добавить кассу',
    'back' => 'Назад',
    'save_account' => 'Сохранить кассу',

    'name' => 'Название кассы',
    'type' => 'Тип кассы',
    'type_main' => 'Основная касса',
    'type_sub' => 'Дополнительная касса',
    'type_cash' => 'Наличные (касса)',
    'type_bank' => 'Банковский счёт',
    'type_owner_cash' => 'Касса владельца',
    'type_instapay' => 'InstaPay',
    'parent_account' => 'Родительская касса',
    'select_parent' => 'Выберите родительскую кассу',
    'opening_balance' => 'Начальный баланс',
    'balance_readonly_hint' => 'Баланс можно изменить только через операции по кассе.',

    'save_changes' => 'Сохранить изменения',
    'cancel' => 'Отмена',

    'created_success' => 'Касса успешно создана.',
    'updated_success' => 'Касса успешно обновлена.',

    'column_type' => 'Тип',
    'column_balance' => 'Баланс',
    'badge_main' => 'Основная',
    'badge_sub' => 'Дополнительная',
    'confirm_delete' => 'Удалить кассу?',

    'validation' => [
        'name_required' => 'Название кассы обязательно.',
        'type_required' => 'Выберите корректный тип кассы.',
        'parent_invalid' => 'Выбранная родительская касса недействительна.',
        'parent_circular' => 'Этот выбор создаст циклическую иерархию касс.',
        'balance_numeric' => 'Баланс должен быть корректным числом.',
    ],

];
