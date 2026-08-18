<?php

return [
    'title' => 'Terms',
    'academic_year' => 'Academic year',
    'create' => 'Add term',
    'edit' => 'Edit term',
    'name' => 'Term name',
    'order' => 'Order',
    'start_date' => 'Start date',
    'end_date' => 'End date',
    'actions' => 'Actions',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'back' => 'Back to academic years',
    'delete' => 'Delete',
    'confirm_delete' => 'Delete this term?',
    'no_data' => 'No terms have been configured for this academic year.',
    'locked_hint' => 'This historical academic year is locked. Terms are read-only until the year is explicitly unlocked.',
    'created_success' => 'Term created successfully.',
    'updated_success' => 'Term updated successfully.',
    'deleted_success' => 'Term deleted successfully.',
    'validation' => [
        'end_after_start' => 'The term end date must be after its start date.',
        'within_year' => 'Term dates must be within the selected academic year.',
        'overlap' => 'The term dates overlap another term in this academic year.',
    ],
];
