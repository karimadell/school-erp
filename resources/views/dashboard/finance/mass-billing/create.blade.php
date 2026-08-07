@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <h2>{{ __('mass_billing.create_title') }}</h2>
    <p class="text-muted">{{ __('mass_billing.subtitle') }}</p>

    <form method="POST" action="{{ route('dashboard.finance.mass-billing.store') }}" class="card card-body">
        @csrf
        @include('dashboard.finance.mass-billing._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('mass_billing.actions.create') }}</button>
            <a href="{{ route('dashboard.finance.mass-billing.index') }}" class="btn btn-outline-secondary">{{ __('mass_billing.actions.back') }}</a>
        </div>
    </form>
</div>
@endsection
