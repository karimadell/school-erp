@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">💰 {{ __('cash_operations.title') }}</h3>
            <small class="text-muted">{{ __('cash_operations.subtitle') }}</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @can('open cash sessions')
                <a href="{{ route('dashboard.cash.sessions.create') }}" class="btn btn-outline-secondary">{{ __('cash_operations.open_shift_action') }}</a>
            @endcan
            @can('close cash sessions')
                <a href="{{ route('dashboard.cash.sessions.index') }}" class="btn btn-outline-secondary">{{ __('cash_operations.close_shift_action') }}</a>
            @endcan
            @canany(['manage cash', 'transfer cash'])
                <a href="{{ route('dashboard.cash.transfer.form') }}" class="btn btn-outline-primary">{{ __('cash_operations.generic_transfer_action') }}</a>
                <a href="{{ route('dashboard.cash.operations.owner-return.create') }}" class="btn btn-outline-success">{{ __('cash_operations.owner_return_action') }}</a>
                <a href="{{ route('dashboard.cash.operations.handover.create') }}" class="btn btn-success">{{ __('cash_operations.handover_action') }}</a>
            @endcanany
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-warning shadow-sm border-0">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-4">
        @foreach($roles as $role)
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase mb-2">{{ $role['label'] }}</div>
                        @if($role['account'])
                            <div class="fw-semibold">{{ $role['account']->name }}</div>
                            <div class="fs-4 fw-bold">{{ number_format((float) $role['account']->balance, 2) }}</div>
                            <div class="small text-muted">{{ __('cash_operations.current_balance') }}</div>
                            <div class="d-flex justify-content-between small mt-2">
                                <span class="text-success">{{ __('cash_operations.today_in') }}: {{ number_format((float) $role['today_in'], 2) }}</span>
                                <span class="text-danger">{{ __('cash_operations.today_out') }}: {{ number_format((float) $role['today_out'], 2) }}</span>
                            </div>
                        @else
                            <div class="text-muted small">{{ __('cash_operations.no_accounts') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">{{ __('cash_operations.recent_transfers') }}</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('cash_operations.from') }}</th>
                        <th>{{ __('cash_operations.to') }}</th>
                        <th class="text-end">{{ __('app.amount') }}</th>
                        <th>{{ __('cash.receipt_number') }}</th>
                        <th>Тип</th>
                        <th>{{ __('app.date') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransfers as $transfer)
                        <tr>
                            <td>{{ $transfer->fromAccount?->name }}</td>
                            <td>{{ $transfer->toAccount?->name }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $transfer->amount, 2) }}</td>
                            <td>{{ $transfer->receipt_number }}</td>
                            <td><span class="badge bg-secondary">{{ __('cash_operations.transfer_type.'.$transfer->transfer_type) }}</span></td>
                            <td>{{ optional($transfer->transfer_date)->format('d.m.Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-muted">{{ __('cash_operations.no_transfers') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
