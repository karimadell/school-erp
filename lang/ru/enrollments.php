<?php

return [
    'title' => 'Зачисления',
    'create' => 'Добавить зачисление',
    'edit' => 'Редактировать зачисление',
    'history' => 'История зачислений',
    'select_student' => 'Выберите ученика',

    'student' => 'Студент',
    'academic_year' => 'Учебный год',
    'stage' => 'Этап обучения',
    'grade' => 'Параллель',
    'class' => 'Класс',

    'enrollment_date' => 'Дата зачисления',
    'status' => 'Статус',
    'notes' => 'Примечания',

    'select_academic_year' => 'Выберите учебный год',
    'no_academic_year' => 'Нет доступного учебного года',
    'select_stage' => 'Выберите этап',
    'select_grade' => 'Выберите параллель',
    'select_class' => 'Выберите класс',
    'select_status' => 'Выберите статус',

    'status_active' => 'Активен',
    'status_transferred' => 'Переведён',
    'status_withdrawn' => 'Отчислен',
    'status_graduated' => 'Выпускник',

    'save' => 'Сохранить',
    'back' => 'Назад',
    'cancel' => 'Отмена',
    'delete' => 'Удалить',

    'created_success' => 'Зачисление успешно добавлено',
    'updated_success' => 'Зачисление обновлено',
    'deleted_success' => 'Зачисление удалено',

    'confirm_delete' => 'Вы уверены?',
    'no_data' => 'Нет данных',
    'validation' => [
        'grade_stage_mismatch' => 'Выбранная параллель не относится к указанной ступени.',
        'class_grade_mismatch' => 'Выбранная учебная группа не относится к указанной параллели.',
        'inactive_stage' => 'Выбранная ступень неактивна.',
        'inactive_class' => 'Выбранная учебная группа неактивна.',
        'structure_changed' => 'Структура школы изменилась. Обновите страницу и повторите попытку.',
    ],
];
