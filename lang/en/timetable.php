<?php

// Minimal file: only the keys the Attendance module's own views reference
// (take.blade.php's period-column header). The Timetable module's own
// full key set (matching resources/lang/ru/timetable.php) is out of
// scope here and belongs to that module's own localization work.
return [
    'lesson' => 'Lesson',
    'generation_no_active_year' => 'No active academic year is selected. The previous timetable was preserved.',
    'generation_inactive_class' => 'A timetable cannot be generated for an inactive or invalid class.',
    'generation_no_curriculum' => 'The class has no active curriculum. The previous timetable was preserved.',
    'generation_inactive_subject' => 'The curriculum contains an inactive subject. The previous timetable was preserved.',
    'generation_missing_teacher' => 'One or more subjects have no active assigned teacher.',
    'generation_insufficient_slots' => 'There are not enough available periods for the required weekly curriculum.',
    'generation_incomplete' => 'A complete timetable could not be generated. The previous timetable was preserved.',
    'school_wide_title' => 'School timetable',
    'school_wide_navigation' => 'School timetable',
    'filter_classes' => 'Classes',
    'all_classes' => 'All classes',
    'apply_filter' => 'Apply',
    'no_lessons_yet' => 'No lessons have been scheduled yet',
    'teacher_conflict_badge' => 'Teacher conflict',
    'class_conflict_badge' => 'Class conflict',
    'duplicate_cell_badge' => 'Duplicate entry',
    'empty_slot' => 'No lesson',
    'no_active_classes' => 'There are no active classes to display.',
    'open_class_timetable' => 'Open class timetable',
];
