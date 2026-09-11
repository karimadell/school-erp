@php
    $badgeClass = match ($status) {
        \App\Models\Expense::STATUS_DRAFT => 'bg-secondary',
        \App\Models\Expense::STATUS_APPROVED => 'bg-warning text-dark',
        \App\Models\Expense::STATUS_PAID => 'bg-success',
        \App\Models\Expense::STATUS_VOID => 'bg-danger',
        default => 'bg-secondary',
    };
@endphp
<span class="badge {{ $badgeClass }}">{{ __('expenses.status_'.$status) }}</span>
