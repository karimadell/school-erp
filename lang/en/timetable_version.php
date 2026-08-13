<?php

return [
    'navigation_group' => 'Academic Process', 'navigation' => 'Timetable Versions',
    'model' => 'Timetable Version', 'models' => 'Timetable Versions',
    'fields' => [
        'academic_year' => 'Academic Year', 'name' => 'Name', 'status' => 'Status',
        'effective_from' => 'Effective From', 'effective_to' => 'Effective To', 'notes' => 'Notes',
        'published_by' => 'Published By', 'published_at' => 'Published At',
    ],
    'statuses' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'],
    'actions' => ['publish' => 'Publish'],
    'messages' => ['published' => 'The timetable version has been published.'],
    'validation' => [
        'published_immutable' => 'A published timetable version cannot be edited.',
        'invalid_status' => 'The timetable version status is invalid.',
        'invalid_dates' => 'The effective end date must be on or after the start date.',
        'outside_academic_year' => 'The effective dates must fall within the academic year.',
        'publish_through_service' => 'Publish timetable versions through the publication workflow.',
        'only_draft_publishable' => 'Only a draft timetable version can be published.',
        'hard_delete_forbidden' => 'Timetable versions cannot be deleted because they are historical records.',
        'invalid_weekday' => 'Weekday must be between 1 and 7.',
        'header_version_mismatch' => 'The timetable header must belong to the same version as the entry.',
        'cross_year_reference' => 'The selected reference must belong to the timetable version academic year.',
        'period_schedule_mismatch' => 'The selected period must belong to the selected bell schedule.',
    ],
];
