@extends('layouts.dashboard')

@section('content')
<div class="container py-4" style="max-width: 640px;">

    <h3 class="fw-bold mb-2">{{ __('cash_operations.owner_return_title') }}</h3>
    <p class="text-muted">{{ __('cash_operations.owner_return_hint') }}</p>

    @if($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.cash.operations.owner-return.store') }}" class="card border-0 shadow-sm">
        <div class="card-body">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            {{-- The canonical owner/operating accounts are resolved
                 server-side by role, not chosen here — see
                 CashOperationsController. --}}
            <div class="mb-3">
                <div class="text-muted small">{{ __('cash_operations.from_account') }}</div>
                <div class="fw-semibold">{{ $owner->name }} ({{ number_format((float) $owner->balance, 2) }})</div>
            </div>
            <div class="mb-3">
                <div class="text-muted small">{{ __('cash_operations.to_account') }}</div>
                <div class="fw-semibold">{{ $operating->name }}</div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('cash_operations.amount_to_transfer') }}</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ old('amount') }}" required>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('app.notes') }}</label>
                <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('cash_operations.owner_return_action') }}?')">
                    {{ __('cash_operations.owner_return_action') }}
                </button>
                <a href="{{ route('dashboard.cash.operations.index') }}" class="btn btn-secondary">{{ __('app.cancel') }}</a>
            </div>
        </div>
    </form>
</div>
@endsection
