<?php

// Phase 3 — Cash-drawer sessions. Matches lang/ru/cash_sessions.php keys.

return [
    'title' => 'Cash sessions',
    'history' => 'Cash session history',
    'session' => 'Cash session',
    'session_number' => 'Session #:id',

    // Actions
    'open_session' => 'Open session',
    'close_session' => 'Close session',
    'back' => 'Back',
    'open' => 'Open',
    'close' => 'Close',

    // Entities
    'drawer' => 'Cash drawer',
    'cashier' => 'Cashier',
    'opened_by' => 'Opened by',
    'closed_by' => 'Closed by',
    'opened_at' => 'Opened at',
    'closed_at' => 'Closed at',

    // Balances
    'opening_balance' => 'Opening balance',
    'income' => 'Cash in',
    'outflow' => 'Cash out',
    'expected_balance' => 'Expected balance',
    'actual_balance' => 'Actual balance',
    'variance' => 'Variance',
    'shortage' => 'Shortage',
    'overage' => 'Overage',
    'no_variance' => 'No variance',
    'variance_reason' => 'Variance reason',

    // Status
    'status' => 'Status',
    'status_open' => 'Session open',
    'status_closed' => 'Session closed',

    // Opening baseline provenance
    'opening_source' => 'Opening balance source',
    'source_previous_session' => 'From the previous closed session',
    'source_account_balance' => 'From the cash account balance',
    'source_override' => 'Manual override',

    // Open form
    'open_title' => 'Open a cash session',
    'select_account' => 'Select a cash drawer',
    'open_note' => 'Opening note',
    'open_note_placeholder' => 'Optional',
    'no_available_drawers' => 'No available cash drawers to open a session.',
    'already_open' => 'Session already open',

    // Show / activity
    'activity' => 'Session activity',
    'transactions' => 'Transactions',
    'no_transactions' => 'No transactions in this session yet.',
    'transaction_type_in' => 'Cash in',
    'transaction_type_out' => 'Cash out',
    'amount' => 'Amount',
    'description' => 'Description',
    'date' => 'Date',
    'reconciliation' => 'Cash reconciliation',

    // Close form
    'close_title' => 'Close a cash session',
    'counted_total' => 'Counted total',
    'counted_total_hint' => 'Recount the cash in the drawer and enter the total.',
    'close_note' => 'Variance reason',
    'close_note_hint' => 'Required when the counted total differs from the expected balance.',
    'variance_warning' => 'A variance will be recorded as a shortage or an overage.',
    'cannot_close_with_variance' => 'You are not allowed to close a session with a variance.',

    // Index
    'active_session' => 'Open session',
    'no_active_session' => 'No open session',
    'view' => 'Open',
    'no_history' => 'No cash sessions yet.',

    // Flash
    'opened_success' => 'Session opened.',
    'closed_success' => 'Session closed.',
];
