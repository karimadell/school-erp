@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('cash_sessions.title') }}</h1>
        @can('open cash sessions')
            <a href="{{ route('dashboard.cash.sessions.create') }}" class="btn btn-primary">{{ __('cash_sessions.open_session') }}</a>
        @endcan
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    {{-- Drawers and their current shift status --}}
    <div class="row g-3 mb-4">
        @forelse($drawers as $row)
            @php($active = $row['active'])
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h2 class="h5 mb-1">{{ $row['account']->name }}</h2>
                            @if($active)
                                <span class="badge bg-success">{{ __('cash_sessions.status_open') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ __('cash_sessions.no_active_session') }}</span>
                            @endif
                        </div>
                        @if($active)
                            <div class="small text-muted mb-2">
                                {{ __('cash_sessions.cashier') }}: {{ $active->opener?->name ?? '—' }} ·
                                {{ __('cash_sessions.opened_at') }}: {{ $active->opened_at?->format('d.m.Y H:i') }}
                            </div>
                            <a href="{{ route('dashboard.cash.sessions.show', $active) }}" class="btn btn-sm btn-outline-primary">{{ __('cash_sessions.view') }}</a>
                        @else
                            @can('open cash sessions')
                                <form method="POST" action="{{ route('dashboard.cash.sessions.store') }}" class="mt-2">@csrf
                                    <input type="hidden" name="cash_account_id" value="{{ $row['account']->id }}">
                                    <button class="btn btn-sm btn-primary">{{ __('cash_sessions.open_session') }}</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-light border">{{ __('cash_sessions.no_available_drawers') }}</div></div>
        @endforelse
    </div>

    {{-- Full history --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h2 class="h5 mb-0">{{ __('cash_sessions.history') }}</h2></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('cash_sessions.drawer') }}</th>
                        <th>{{ __('cash_sessions.cashier') }}</th>
                        <th>{{ __('cash_sessions.opened_at') }}</th>
                        <th>{{ __('cash_sessions.closed_at') }}</th>
                        <th class="text-end">{{ __('cash_sessions.variance') }}</th>
                        <th>{{ __('cash_sessions.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($history as $session)
                        <tr>
                            <td>{{ $session->id }}</td>
                            <td>{{ $session->account?->name }}</td>
                            <td>{{ $session->opener?->name ?? '—' }}</td>
                            <td>{{ $session->opened_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $session->closed_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="text-end">
                                @if($session->isClosed())
                                    @php($v = (string) $session->variance)
                                    @if(bccomp($v, '0.00', 2) === 0)
                                        <span class="text-success">0.00</span>
                                    @elseif(bccomp($v, '0.00', 2) < 0)
                                        <span class="text-danger">{{ $v }}</span>
                                    @else
                                        <span class="text-warning">+{{ $v }}</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if($session->isOpen())
                                    <span class="badge bg-success">{{ __('cash_sessions.status_open') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('cash_sessions.status_closed') }}</span>
                                @endif
                            </td>
                            <td class="text-end"><a href="{{ route('dashboard.cash.sessions.show', $session) }}" class="btn btn-sm btn-outline-secondary">{{ __('cash_sessions.view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">{{ __('cash_sessions.no_history') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">{{ $history->links() }}</div>
    </div>
</div>
@endsection
