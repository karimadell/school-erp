{{-- Finance V2, Phase 2A — service-attribution status badge. --}}
@switch($status)
    @case(\App\Services\Finance\PaymentAllocationStatus::FullyAttributed)
        <span class="badge bg-success">Распределено</span>
        @break
    @case(\App\Services\Finance\PaymentAllocationStatus::Unallocated)
        <span class="badge bg-secondary">Не распределено</span>
        @break
    @case(\App\Services\Finance\PaymentAllocationStatus::NeedsReview)
        <span class="badge bg-danger">Требует проверки</span>
        @break
@endswitch
