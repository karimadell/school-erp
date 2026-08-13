<?php

return [
    'navigation_group' => 'Academic Process',
    'navigation' => 'Academic Calendar',
    'model' => 'Academic Calendar',
    'models' => 'Academic Calendars',
    'fields' => [
        'academic_year' => 'Academic Year', 'weekly_days_off' => 'Weekly Days Off',
        'default_bell_schedule_id' => 'Default Bell Schedule ID', 'events' => 'Events',
        'updated_at' => 'Updated', 'name' => 'Name', 'type' => 'Type',
        'start_date' => 'Start Date', 'end_date' => 'End Date', 'effect' => 'Effect',
        'bell_schedule_id' => 'Bell Schedule ID', 'shift' => 'Shift', 'notes' => 'Notes',
        'is_active' => 'Active',
    ],
    'help' => ['default_bell_schedule_id' => 'May be filled after the bell schedule module is installed.'],
    'weekdays' => [
        'sun' => 'Sunday', 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
        'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday',
    ],
    'events' => ['title' => 'Events and Exceptions'],
    'types' => [
        'official_holiday' => 'Official Holiday', 'school_holiday' => 'School Holiday',
        'school_event' => 'School Event', 'teaching_override' => 'Teaching Override',
        'bell_schedule_override' => 'Bell Schedule Override',
    ],
    'effects' => [
        'non_teaching' => 'Non-teaching Day', 'teaching_day' => 'Teaching Day',
        'shortened' => 'Shortened Teaching Day',
    ],
    'validation' => [
        'active_academic_year_required' => 'A new academic calendar may only be created for the active academic year.',
        'weekly_days_off' => 'Select at least one valid weekly day off.',
        'invalid_event_type' => 'The calendar event type is invalid.',
        'end_before_start' => 'The end date cannot be before the start date.',
        'event_outside_year' => 'The event must be within the selected academic year.',
        'teaching_override_effect' => 'Select the teaching-day override effect.',
        'bell_schedule_required' => 'A bell schedule is required for a bell schedule override.',
        'bell_override_single_day' => 'A bell schedule override may only apply to one day.',
    ],
];
