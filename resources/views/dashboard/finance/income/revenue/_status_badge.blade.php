@php
    $badgeClass = match ($status) {
        \App\Models\RevenueEntry::STATUS_DRAFT => 'bg-secondary',
        \App\Models\RevenueEntry::STATUS_POSTED => 'bg-success',
        \App\Models\RevenueEntry::STATUS_REVERSED => 'bg-danger',
        default => 'bg-secondary',
    };
@endphp
<span class="badge {{ $badgeClass }}">{{ __('revenues.status_'.$status) }}</span>
