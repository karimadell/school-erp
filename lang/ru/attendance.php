<?php

/**
 * Batch 9: backfills only the keys the Teacher Portal's
 * teacher-attendance.blade.php actually references — this file is
 * shared with the dashboard and admin Attendance surfaces (see
 * app/Http/Controllers/Dashboard/AttendanceController.php,
 * app/Filament/Resources/Attendances), which use additional keys of
 * their own (class, date, period, reports, take_attendance, etc.) not
 * added here. Completing those surfaces' localization is out of this
 * batch's scope (Teacher Portal only).
 */
return [
    'select_class' => 'Выберите класс',
    'load' => 'Загрузить',
    'title' => 'Посещаемость',
    'student' => 'Студент',
    'status' => 'Статус',
    'present' => 'Присутствует',
    'absent' => 'Отсутствует',
    'late' => 'Опоздал',
    'excused' => 'Освобождён',
    'save' => 'Сохранить',
    'saved_success' => 'Посещаемость сохранена.',
];
