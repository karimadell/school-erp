@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('cash_sessions.session') }} #{{ $session->id }}</h1>
        <div>
            @if($session->isOpen())
                <span class="badge bg-success fs-6">{{ __('cash_sessions.status_open') }}</span>
            @else
                <span class="badge bg-secondary fs-6">{{ __('cash_sessions.status_closed') }}</span>
            @endif
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            {{-- Session identity --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-6">{{ __('cash_sessions.drawer') }}</dt><dd class="col-6 text-end">{{ $session->account?->name }}</dd>
                        <dt class="col-6">{{ __('cash_sessions.opened_by') }}</dt><dd class="col-6 text-end">{{ $session->opener?->name ?? '—' }}</dd>
                        <dt class="col-6">{{ __('cash_sessions.opened_at') }}</dt><dd class="col-6 text-end">{{ $session->opened_at?->format('d.m.Y H:i') }}</dd>
                        @if($session->isClosed())
                            <dt class="col-6">{{ __('cash_sessions.closed_by') }}</dt><dd class="col-6 text-end">{{ $session->closer?->name ?? '—' }}</dd>
                            <dt class="col-6">{{ __('cash_sessions.closed_at') }}</dt><dd class="col-6 text-end">{{ $session->closed_at?->format('d.m.Y H:i') }}</dd>
                        @endif
                        <dt class="col-6">{{ __('cash_sessions.opening_source') }}</dt>
                        <dd class="col-6 text-end">{{ __('cash_sessions.source_' . $session->opening_expected_source) }}</dd>
                    </dl>
                    @if($session->open_note)
                        <div class="small text-muted mt-2">{{ $session->open_note }}</div>
                    @endif
                </div>
            </div>

            {{-- Reconciliation --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h2 class="h5 mb-0">{{ __('cash_sessions.reconciliation') }}</h2></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7">{{ __('cash_sessions.opening_balance') }}</dt><dd class="col-5 text-end">{{ $session->opening_expected }} EGP</dd>
                        <dt class="col-7 text-success">{{ __('cash_sessions.income') }}</dt><dd class="col-5 text-end text-success">+{{ $cashIn }} EGP</dd>
                        <dt class="col-7 text-danger">{{ __('cash_sessions.outflow') }}</dt><dd class="col-5 text-end text-danger">−{{ $cashOut }} EGP</dd>
                        <dt class="col-7 border-top pt-2">{{ __('cash_sessions.expected_balance') }}</dt>
                        <dd class="col-5 text-end border-top pt-2"><strong>{{ $expected }} EGP</strong></dd>
                        @if($session->isClosed())
                            <dt class="col-7">{{ __('cash_sessions.actual_balance') }}</dt><dd class="col-5 text-end">{{ $session->closing_counted }} EGP</dd>
                            @php($v = (string) $session->variance)
                            @if(bccomp($v, '0.00', 2) === 0)
                                <dt class="col-7">{{ __('cash_sessions.variance') }}</dt><dd class="col-5 text-end text-success">{{ __('cash_sessions.no_variance') }}</dd>
                            @elseif(bccomp($v, '0.00', 2) < 0)
                                <dt class="col-7 text-danger">{{ __('cash_sessions.shortage') }}</dt><dd class="col-5 text-end text-danger"><strong>{{ $v }} EGP</strong></dd>
                            @else
                                <dt class="col-7 text-warning">{{ __('cash_sessions.overage') }}</dt><dd class="col-5 text-end text-warning"><strong>+{{ $v }} EGP</strong></dd>
                            @endif
                        @endif
                    </dl>
                    @if($session->isClosed() && $session->close_note)
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>{{ __('cash_sessions.variance_reason') }}:</strong> {{ $session->close_note }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Close form (open sessions only) --}}
            @if($session->isOpen() && $canClose)
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h2 class="h5 mb-0">{{ __('cash_sessions.close_title') }}</h2></div>
                    <form method="POST" action="{{ route('dashboard.cash.sessions.close', $session) }}">@csrf
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="closing_counted">{{ __('cash_sessions.counted_total') }}, EGP <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="closing_counted" type="number" step="0.01" min="0" name="closing_counted"
                                       value="{{ old('closing_counted') }}"
                                       class="form-control @error('closing_counted') is-invalid @enderror" required>
                                <div class="form-text">{{ __('cash_sessions.counted_total_hint') }}</div>
                                @error('closing_counted')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="close_note">{{ __('cash_sessions.close_note') }}</label>
                                <input id="close_note" type="text" name="close_note" maxlength="500"
                                       value="{{ old('close_note') }}"
                                       class="form-control @error('close_note') is-invalid @enderror">
                                <div class="form-text">{{ __('cash_sessions.close_note_hint') }}</div>
                                @error('close_note')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            @unless($canCloseWithVariance)
                                <div class="small text-muted mb-2">{{ __('cash_sessions.cannot_close_with_variance') }}</div>
                            @endunless
                            <button class="btn btn-primary">{{ __('cash_sessions.close_session') }}</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        {{-- Activity --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h2 class="h5 mb-0">{{ __('cash_sessions.activity') }}</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('cash_sessions.date') }}</th>
                                <th>{{ __('cash_sessions.description') }}</th>
                                <th class="text-end">{{ __('cash_sessions.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($session->transactions as $tx)
                                <tr>
                                    <td class="small text-muted">{{ $tx->created_at?->format('d.m.Y H:i') }}</td>
                                    <td>{{ $tx->description }}</td>
                                    <td class="text-end {{ $tx->isIn() ? 'text-success' : 'text-danger' }}">
                                        {{ $tx->isIn() ? '+' : '−' }}{{ $tx->amount }} EGP
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">{{ __('cash_sessions.no_transactions') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('dashboard.cash.sessions.index') }}" class="btn btn-outline-secondary">{{ __('cash_sessions.back') }}</a>
            </div>
        </div>
    </div>
</div>
@endsection
