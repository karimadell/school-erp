<?php

return [
    'delete_blocked' => 'Deletion is not possible because this record is already used by: :dependencies. School history was not changed.',
    'dependencies' => [
        'active_year' => 'the active academic year', 'enrollments' => 'enrollments', 'quarters' => 'terms',
        'curricula' => 'curriculum', 'teacher_assignments' => 'teacher assignments',
        'class_teachers' => 'class teachers', 'exams' => 'exams', 'timetable' => 'timetable',
        'finance' => 'finance records', 'grades' => 'grades', 'classes' => 'classes',
        'students' => 'students', 'grades_history' => 'student grades',
        'journal' => 'lesson journal', 'calendar' => 'academic calendar',
        'bell_schedules' => 'bell schedules', 'classrooms' => 'classrooms',
        'finance_prices' => 'tariffs', 'finance_services' => 'services', 'billing' => 'mass billing',
        'unlocks' => 'unlock history', 'holidays' => 'holiday calendar',
        'legacy_classes' => 'legacy classes', 'legacy_timetable' => 'timetable', 'subjects' => 'class subjects',
    ],
];
