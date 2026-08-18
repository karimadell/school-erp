<?php

return [
    'delete_blocked' => 'Удаление невозможно: объект уже используется (:dependencies). История школы не была изменена.',
    'dependencies' => [
        'active_year' => 'активный учебный год', 'enrollments' => 'зачисления', 'quarters' => 'четверти',
        'curricula' => 'учебный план', 'teacher_assignments' => 'назначения учителей',
        'class_teachers' => 'классные руководители', 'exams' => 'экзамены', 'timetable' => 'расписание',
        'finance' => 'финансовые записи', 'grades' => 'классы', 'classes' => 'учебные группы',
        'students' => 'ученики', 'grades_history' => 'оценки учеников',
        'journal' => 'электронный журнал', 'calendar' => 'академический календарь',
        'bell_schedules' => 'расписания звонков', 'classrooms' => 'кабинеты',
        'finance_prices' => 'тарифы', 'finance_services' => 'услуги', 'billing' => 'массовые начисления',
        'unlocks' => 'история разблокировок', 'holidays' => 'календарь выходных',
        'legacy_classes' => 'архивные классы', 'legacy_timetable' => 'расписание', 'subjects' => 'предметы класса',
    ],
];
