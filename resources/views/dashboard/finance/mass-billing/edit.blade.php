@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <p class="text-muted small mb-1">{{ __('mass_billing.title') }} · {{ __('mass_billing.context.edit') }}</p>
    <h2 class="mb-1">{{ __('mass_billing.title') }}</h2>
    <p class="text-muted">{{ __('mass_billing.subtitle') }}</p>

    <form method="POST" action="{{ route('dashboard.finance.mass-billing.update', $batch) }}" class="card card-body">
        @csrf
        @method('PUT')
        @include('dashboard.finance.mass-billing._form')
        <div class="mt-4 d-flex flex-wrap align-items-center gap-2">
            <button class="btn btn-primary">{{ __('mass_billing.actions.update') }}</button>
            <a href="{{ route('dashboard.finance.mass-billing.show', $batch) }}" class="btn btn-outline-secondary">{{ __('mass_billing.actions.back') }}</a>
            <span class="text-muted small">{{ __('mass_billing.create_action_note') }}</span>
        </div>
    </form>
</div>
@endsection
