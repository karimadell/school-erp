<?php

return [

    'title' => 'Academic Years',
    'list' => 'Academic Years List',
    'list_hint' => 'Manage the school\'s academic years',

    'create' => 'Add Academic Year',
    'create_hint' => 'Add a new academic year to the system',

    'edit' => 'Edit Academic Year',
    'edit_hint' => 'Change academic year data',

    'year_info' => 'Academic Year Data',

    'id' => 'ID',
    'name' => 'Name',
    'name_placeholder' => 'E.g.: 2026 / 2027',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'is_active' => 'Active Academic Year',
    'is_active_hint' => 'Only one academic year can be active at a time — activating this one automatically deactivates the others.',

    'status' => 'Status',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'locked_status' => 'Historical — locked',
    'unlocked' => 'Temporarily unlocked',

    'actions' => 'Actions',
    'total' => 'total',

    'save' => 'Save',
    'cancel' => 'Cancel',
    'back' => 'Back',
    'delete' => 'Delete',
    'edit_btn' => 'Edit',

    'created_success' => 'Academic year created successfully',
    'updated_success' => 'Academic year updated successfully',
    'deleted_success' => 'Academic year deleted successfully',

    'confirm_delete' => 'Are you sure you want to delete this academic year?',

    'no_data' => 'No data available',
    'validation_error' => 'Please check the entered data',
    'locked' => 'This academic year is locked. Ask an administrator for a temporary unlock.',
    'locked_activation_only' => 'A locked academic year cannot be edited. It may only be activated without changing its name or dates.',
    'locked_activation_hint' => 'This historical year is locked: its name and dates are read-only. You may activate it; correcting historical data requires an explicit temporary unlock.',
    'unresolvable' => 'The academic year for this record cannot be determined.',
    'unlock' => 'Unlock',
    'unlock_reason' => 'Reason',
    'unlock_expires_at' => 'Unlock until',
    'unlock_success' => 'Academic year temporarily unlocked.',
    'unlocked_until' => 'Unlocked until :date',
    'validation' => ['end_after_start' => 'The academic year end date must be after its start date.'],

];
