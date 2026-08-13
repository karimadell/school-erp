<?php

return [
    'title' => 'Timetable Entries',
    'fields' => [
        'weekday' => 'Weekday', 'bell_schedule' => 'Bell Schedule', 'period' => 'Period',
        'class' => 'Class / Group', 'subject' => 'Subject', 'teacher' => 'Teacher',
        'classroom' => 'Classroom',
    ],
    'filters' => ['weekday' => 'Weekday', 'class' => 'Class / Group', 'teacher' => 'Teacher'],
    'weekdays' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
    'validation' => [
        'draft_only' => 'Timetable entries may only be changed in a draft version.',
        'required_reference' => 'This timetable reference is required.',
        'invalid_weekday' => 'Weekday must be between 1 and 7.',
        'header_version_mismatch' => 'The timetable header belongs to another version.',
        'cross_year_schedule' => 'The bell schedule belongs to another academic year.',
        'period_schedule_mismatch' => 'The period does not belong to the selected bell schedule.',
        'cross_year_classroom' => 'The classroom belongs to another academic year.',
        'inactive_classroom' => 'The selected classroom is inactive.',
        'cross_year_curriculum' => 'The curriculum belongs to another academic year.',
        'subject_curriculum_mismatch' => 'The subject does not match the selected curriculum.',
        'class_curriculum_mismatch' => 'The class grade does not match the curriculum grade.',
        'cross_year_assignment' => 'The teacher assignment belongs to another academic year.',
        'assignment_mismatch' => 'The teacher assignment does not match the selected class and subject.',
        'duplicate_entry' => 'This lesson already exists in the selected slot.',
        'teacher_conflict' => 'The teacher already has another lesson in this slot.',
        'class_conflict' => 'The class already has another lesson in this slot.',
        'classroom_conflict' => 'The classroom is already occupied in this slot.',
    ],
];
