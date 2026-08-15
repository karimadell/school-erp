<?php

return [
    'navigation_group' => 'Учителя и сотрудники',
    'navigation' => 'Назначения учителей',
    'model' => 'Назначение учителя',
    'models' => 'Назначения учителей',
    'fields' => [
        'teacher' => 'Учитель',
        'class' => 'Класс',
        'subject' => 'Предмет',
        'academic_year' => 'Учебный год',
        'created_at' => 'Создано',
    ],
    'filters' => ['academic_year' => 'Учебный год', 'class' => 'Класс'],
    'validation' => ['duplicate' => 'Этот учитель уже назначен на этот класс и предмет в этом учебном году.'],
    'empty_heading' => 'Назначений учителей пока нет',
    'empty_description' => 'Назначьте учителя на класс, предмет и учебный год.',
];
