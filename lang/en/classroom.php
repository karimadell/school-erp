<?php

return [
    'navigation_group' => 'Academic Process', 'navigation' => 'Classrooms',
    'model' => 'Classroom', 'models' => 'Classrooms',
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
