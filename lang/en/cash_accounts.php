<?php

return [

    'index_title' => 'Cash Accounts',
    'add_new' => 'Add New Account',
    'edit_title' => 'Edit Cash Account',
    'edit_account' => 'Edit Account',
    'create_title' => 'Add Cash Account',
    'back' => 'Back',
    'save_account' => 'Save Account',

    'name' => 'Account Name',
    'type' => 'Account Type',
    'type_main' => 'Main Account',
    'type_sub' => 'Sub Account',
    'parent_account' => 'Parent Account',
    'select_parent' => 'Select Parent',
    'opening_balance' => 'Opening Balance',
    'balance_readonly_hint' => 'Balance can only be changed through cash transactions.',

    'save_changes' => 'Save Changes',
    'cancel' => 'Cancel',

    'created_success' => 'Cash account created successfully.',
    'updated_success' => 'Cash account updated successfully.',

    'column_type' => 'Type',
    'column_balance' => 'Balance',
    'badge_main' => 'Main',
    'badge_sub' => 'Sub',
    'confirm_delete' => 'Delete account?',

    'validation' => [
        'name_required' => 'The account name is required.',
        'type_required' => 'Please select a valid account type.',
        'parent_invalid' => 'The selected parent account is invalid.',
        'parent_circular' => 'This selection would create a circular account hierarchy.',
        'balance_numeric' => 'The balance must be a valid number.',
    ],

];
