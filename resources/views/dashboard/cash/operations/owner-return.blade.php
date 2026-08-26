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

            <div class="mb-3">
                <label class="form-label">{{ __('cash_operations.from_account') }} — {{ __('cash_operations.title') }}</label>
                <select name="from_account_id" class="form-select" required onchange="cashOpsUpdateAvailable(this)">
                    <option value="">—</option>
                    @foreach($ownerAccounts as $account)
                        <option value="{{ $account->id }}" data-balance="{{ $account->balance }}" @selected(old('from_account_id') == $account->id)>
                            {{ $account->name }} ({{ number_format((float) $account->balance, 2) }})
                        </option>
                    @endforeach
                </select>
                <div class="form-text">{{ __('cash_operations.available_now') }}: <span id="cash-ops-available">—</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('cash_operations.to_account') }} — {{ __('cash_operations.title') }}</label>
                <select name="to_account_id" class="form-select" required>
                    <option value="">—</option>
                    @foreach($operatingAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
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

<script>
function cashOpsUpdateAvailable(select) {
    var option = select && select.options[select.selectedIndex];
    var balance = option ? parseFloat(option.getAttribute('data-balance') || '0') : 0;
    document.getElementById('cash-ops-available').textContent = option ? balance.toFixed(2) : '—';
}
</script>
@endsection
