@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $title }}</h1>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body py-5 text-center">
            <div class="fs-5 fw-semibold mb-2">{{ __('finance_workspace.income_placeholder_title') }}</div>
            <p class="text-muted mx-auto" style="max-width: 520px;">{{ __('finance_workspace.income_placeholder_body') }}</p>
            <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary mt-3">← {{ __('finance_workspace.income_placeholder_back') }}</a>
        </div>
    </div>
</div>
@endsection
