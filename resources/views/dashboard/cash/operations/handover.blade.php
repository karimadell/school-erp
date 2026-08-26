@extends('layouts.dashboard')

@section('content')
<div class="container py-4" style="max-width: 640px;">

    <h3 class="fw-bold mb-2">{{ __('cash_operations.handover_title') }}</h3>
    <p class="text-muted">{{ __('cash_operations.handover_hint') }}</p>

    @if($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.cash.operations.handover.store') }}" class="card border-0 shadow-sm">
        <div class="card-body">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            {{-- The canonical operating/owner accounts are resolved server-side
                 by role, not chosen here — see CashOperationsController. --}}
            <div class="mb-3">
                <div class="text-muted small">{{ __('cash_operations.from_account') }}</div>
                <div class="fw-semibold">{{ $operating->name }} ({{ number_format((float) $operating->balance, 2) }})</div>
            </div>
            <div class="mb-3">
                <div class="text-muted small">{{ __('cash_operations.to_account') }}</div>
                <div class="fw-semibold">{{ $owner->name }}</div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('cash_operations.amount_to_transfer') }}</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ old('amount') }}"
                       required oninput="cashOpsUpdateRetained(this)" data-available="{{ $operating->balance }}">
                <div class="form-text">{{ __('cash_operations.retained_amount') }}: <span id="cash-ops-retained">{{ number_format((float) $operating->balance, 2) }}</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('app.notes') }}</label>
                <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('cash_operations.handover_action') }}?')">
                    {{ __('cash_operations.handover_action') }}
                </button>
                <a href="{{ route('dashboard.cash.operations.index') }}" class="btn btn-secondary">{{ __('app.cancel') }}</a>
            </div>
        </div>
    </form>
</div>

<script>
function cashOpsUpdateRetained(input) {
    var available = parseFloat(input.getAttribute('data-available') || '0');
    var amount = parseFloat(input.value || '0');
    document.getElementById('cash-ops-retained').textContent = (available - (isNaN(amount) ? 0 : amount)).toFixed(2);
}
</script>
@endsection
