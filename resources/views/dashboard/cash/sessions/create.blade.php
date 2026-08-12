@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <h1 class="h3 mb-4">{{ __('cash_sessions.open_title') }}</h1>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @php($available = $accounts->filter(fn ($row) => ! $row['active']))

    @if($available->isEmpty())
        <div class="alert alert-light border">{{ __('cash_sessions.no_available_drawers') }}</div>
        <a href="{{ route('dashboard.cash.sessions.index') }}" class="btn btn-outline-secondary">{{ __('cash_sessions.back') }}</a>
    @else
        <form method="POST" action="{{ route('dashboard.cash.sessions.store') }}" class="card border-0 shadow-sm">@csrf
            <div class="card-body">
                <label class="form-label">{{ __('cash_sessions.select_account') }} <span class="text-danger" aria-hidden="true">*</span></label>
                <div class="list-group mb-3">
                    @foreach($accounts as $row)
                        @php([$opening, $source] = $row['opening'])
                        <label class="list-group-item d-flex justify-content-between align-items-center {{ $row['active'] ? 'disabled text-muted' : '' }}">
                            <span>
                                <input class="form-check-input me-2" type="radio" name="cash_account_id"
                                       value="{{ $row['account']->id }}" {{ $row['active'] ? 'disabled' : '' }}
                                       {{ old('cash_account_id') == $row['account']->id ? 'checked' : '' }} required>
                                <strong>{{ $row['account']->name }}</strong>
                                @if($row['active'])
                                    <span class="badge bg-success ms-2">{{ __('cash_sessions.already_open') }}</span>
                                @endif
                            </span>
                            @unless($row['active'])
                                <span class="text-end small">
                                    {{ __('cash_sessions.opening_balance') }}: <strong>{{ $opening }} EGP</strong><br>
                                    <span class="text-muted">{{ __('cash_sessions.source_' . $source) }}</span>
                                </span>
                            @endunless
                        </label>
                    @endforeach
                </div>

                <div class="mb-3">
                    <label class="form-label" for="open_note">{{ __('cash_sessions.open_note') }}</label>
                    <input id="open_note" type="text" name="open_note" maxlength="500"
                           value="{{ old('open_note') }}" class="form-control"
                           placeholder="{{ __('cash_sessions.open_note_placeholder') }}">
                </div>

                <button class="btn btn-primary">{{ __('cash_sessions.open_session') }}</button>
                <a href="{{ route('dashboard.cash.sessions.index') }}" class="btn btn-outline-secondary">{{ __('cash_sessions.back') }}</a>
            </div>
        </form>
    @endif
</div>
@endsection
