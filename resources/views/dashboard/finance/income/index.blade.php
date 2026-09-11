@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.income_type_title') }}</h1>
        <p class="text-muted mb-0">{{ __('finance_workspace.income_type_hint') }}</p>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.quick-registration.create') }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_registration') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_registration_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        @can('manage invoices')
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('dashboard.finance.income.students') }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_payment') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.income_type_payment_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-xl-4">
                <a href="{{ route('dashboard.finance.income.students') }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_service') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.income_type_service_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endcan

        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.donation') }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_donation') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_donation_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.other') }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_other') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_other_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <a href="{{ route('dashboard.finance.workspace') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
        @can('manage revenues')
            <a href="{{ route('dashboard.finance.income.revenue.index') }}" class="btn btn-outline-secondary">{{ __('revenues.page_title') }}</a>
        @endcan
    </div>
</div>

<style>
    .income-type-card { transition: box-shadow .15s ease, transform .15s ease; }
    .income-type-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; transform: translateY(-1px); }
</style>
@endsection
