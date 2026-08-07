<?php

return [
    'title' => 'Mass Billing',
    'subtitle' => 'Batch-issue a single service to a group of students for an academic year.',
    'list_title' => 'Billing batches',
    'create_title' => 'New billing batch',
    'edit_title' => 'Edit batch',
    'show_title' => 'Billing batch',
    'preview_title' => 'Preview',
    'preview_hint' => 'Amounts are informational. No invoices are created at this step.',
    'preview_recheck_note' => 'Before invoices are created, the data and tariffs are re-checked.',

    'fields' => [
        'academic_year' => 'Academic year',
        'service' => 'Service or fee',
        'quantity' => 'Quantity',
        'issue_date' => 'Issue date',
        'due_date' => 'Due date',
        'description' => 'Note',
        'target_mode' => 'Who to include',
        'target_mode_all' => 'All students of the year',
        'target_mode_classes' => 'Selected classes',
        'classes' => 'Classes',
        'individual_students' => 'Individual students',
        'include_students' => 'Additionally include students',
        'exclude_students' => 'Exclude students',
    ],

    'actions' => [
        'create' => 'Create batch',
        'save' => 'Save',
        'update' => 'Save changes',
        'preview' => 'Calculate preview',
        'execute' => 'Generate invoices',
        'edit' => 'Edit',
        'back' => 'Back to list',
        'new' => 'New batch',
    ],

    'execute_hint' => 'All amounts will be recalculated on the server. This cannot be undone.',

    'execute_errors' => [
        'already_processing' => 'The batch is already being processed.',
        'already_completed' => 'Invoices for this batch have already been generated.',
        'previously_failed' => 'The batch failed and cannot be re-executed.',
        'not_previewed' => 'Calculate the preview first.',
        'execution_failed' => 'Invoices could not be generated. The execution was rolled back and no invoices were created.',
    ],

    'run' => [
        'title' => 'Execution result',
        'no_run' => 'The batch has not been executed yet.',
        'trigger' => 'Trigger type',
        'trigger_manual' => 'Manual',
        'executor' => 'Executor',
        'executed_at' => 'Executed at',
        'generated_count' => 'Invoices generated',
        'skipped_count' => 'Skipped',
        'failed_count' => 'Failed',
        'total_generated' => 'Total generated',
        'failed_summary' => 'The execution failed. No invoices were created.',
        'invoice' => 'Invoice',
        'view_invoice' => 'View invoice',
        'status' => [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ],
    ],

    'item_status' => [
        'pending' => 'Pending',
        'generated' => 'Invoice created',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
    ],

    'status' => [
        'draft' => 'Draft',
        'previewed' => 'Preview calculated',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'summary' => [
        'selected_count' => 'Students selected',
        'eligible_count' => 'Will be billed',
        'skipped_count' => 'Skipped',
        'expected_invoice_count' => 'Expected invoices',
        'expected_total_amount' => 'Expected total',
    ],

    'table' => [
        'student' => 'Student',
        'class' => 'Class',
        'service' => 'Service',
        'unit_price' => 'Unit price',
        'quantity' => 'Qty',
        'total' => 'Total',
        'eligibility' => 'Status',
        'reason' => 'Skip reason',
        'eligible' => 'Will be billed',
        'skipped' => 'Skipped',
    ],

    'flash' => [
        'created' => 'Billing batch created.',
        'updated' => 'Billing batch updated.',
        'previewed' => 'Preview calculated. No invoices were created.',
        'executed' => 'Invoices generated successfully.',
    ],

    'validation' => [
        'classes_required' => 'Select at least one class.',
        'due_after_issue' => 'The due date cannot be earlier than the issue date.',
    ],

    'empty' => [
        'batches' => 'No billing batches have been created yet.',
        'preview' => 'The preview has not been calculated yet.',
    ],

    'skip_reasons' => [
        'year_inactive' => 'The academic year is not active.',
        'service_inactive' => 'The service is disabled.',
        'no_enrollment' => 'No enrollment for the selected academic year.',
        'enrollment_inactive' => 'The enrollment is not active.',
        'enrollment_withdrawn' => 'The student has withdrawn.',
        'enrollment_graduated' => 'The student has graduated.',
        'enrollment_transferred' => 'The student has transferred.',
        'registration_duplicate' => 'The registration fee has already been charged.',
        'no_tariff' => 'No tariff is configured for the selected date.',
        'pricing_error' => 'The price could not be calculated.',
    ],
];
