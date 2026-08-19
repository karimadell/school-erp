<?php

return [
    'navigation_group' => 'Academic Process', 'navigation' => 'Classrooms',
    'model' => 'Classroom', 'models' => 'Classrooms',
    'search_placeholder' => 'Search by code, name, or building…',
    'active' => 'Active', 'inactive' => 'Inactive',
    'title' => 'Classrooms', 'list_hint' => 'Physical rooms and their capacity.',
    'create' => 'Add Classroom', 'edit' => 'Edit Classroom',
    'back' => 'Back to List', 'save' => 'Save', 'cancel' => 'Cancel',
    'actions' => 'Actions', 'no_data' => 'No classrooms found.', 'filter' => 'Filter',
    'all_academic_years' => 'All Academic Years', 'all_room_types' => 'All Room Types',
    'created_success' => 'Classroom created.', 'updated_success' => 'Classroom updated.',
    'fields' => [
        'academic_year' => 'Academic Year', 'building' => 'Building', 'floor' => 'Floor',
        'code' => 'Room Code', 'name' => 'Name', 'capacity' => 'Capacity',
        'room_type' => 'Room Type', 'is_active' => 'Active', 'notes' => 'Notes',
    ],
    'types' => [
        'classroom' => 'Classroom', 'laboratory' => 'Laboratory',
        'computer_lab' => 'Computer Lab', 'science_lab' => 'Science Lab',
        'art_room' => 'Art Room', 'music_room' => 'Music Room', 'library' => 'Library',
        'sports_hall' => 'Sports Hall', 'auditorium' => 'Auditorium',
        'exam_room' => 'Exam Room', 'meeting_room' => 'Meeting Room', 'other' => 'Other',
    ],
    'validation' => [
        'capacity_min' => 'Capacity must be at least 1.',
        'invalid_room_type' => 'The selected room type is invalid.',
        'duplicate_code' => 'A classroom with this code already exists in this academic year.',
        'hard_delete_forbidden' => 'A classroom cannot be deleted. Deactivate it to preserve history.',
    ],
];
