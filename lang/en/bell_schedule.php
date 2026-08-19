<?php

return [
    'navigation_group' => 'Academic Process', 'navigation' => 'Bell Schedules',
    'model' => 'Bell Schedule', 'models' => 'Bell Schedules',
    'fields' => [
        'academic_year' => 'Academic Year', 'name' => 'Name', 'shift' => 'Shift',
        'is_default' => 'Default', 'is_active' => 'Active', 'notes' => 'Notes',
        'periods' => 'Periods', 'period_number' => 'Period Number', 'label' => 'Label',
        'starts_at' => 'Starts At', 'ends_at' => 'Ends At',
        'break_after_minutes' => 'Break After, Minutes',
    ],
    'help' => ['default' => 'Each shift may have only one active default schedule per academic year.'],
    'title' => 'Bell Schedules', 'list_hint' => 'Shifts, periods, and bell times for the academic year.',
    'create' => 'Add Schedule', 'edit' => 'Edit Schedule',
    'back' => 'Back to List', 'save' => 'Save', 'cancel' => 'Cancel',
    'actions' => 'Actions', 'no_data' => 'No bell schedules found.',
    'created_success' => 'Bell schedule created.', 'updated_success' => 'Bell schedule updated.',
    'periods' => [
        'title' => 'Periods and Bell Times', 'create' => 'Add Period', 'edit' => 'Edit Period',
        'back' => 'Back to Schedule', 'save' => 'Save', 'cancel' => 'Cancel',
        'no_data' => 'No periods added yet.',
        'created_success' => 'Period added.', 'updated_success' => 'Period updated.',
    ],
    'validation' => [
        'shift_min' => 'The shift must be at least 1.',
        'end_after_start' => 'The end time must be after the start time.',
        'duplicate_period_number' => 'This period number is already used in the bell schedule.',
        'period_overlap' => 'The period overlaps another period in this bell schedule.',
    ],
];
