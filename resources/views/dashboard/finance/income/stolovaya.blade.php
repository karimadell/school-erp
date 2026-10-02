@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.stolovaya_chooser_title') }}</h1>
        <p class="text-muted mb-0">{{ __('finance_workspace.stolovaya_chooser_hint') }}</p>
    </div>

    {{-- Rendered only for an actor allowed on BOTH sides (see
         IncomeEntryController::stolovaya()); each option is still gated on
         its own destination permission. --}}
    <div class="row g-3">
        @can('manage invoices')
            <div class="col-md-6">
                <a href="{{ route('dashboard.finance.income.students') }}" class="text-decoration-none" data-stolovaya-option="student">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.stolovaya_chooser_student') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.stolovaya_chooser_student_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endcan

        @can('manage employee stolovaya')
            <div class="col-md-6">
                <a href="{{ route('dashboard.employee-stolovaya.create') }}" class="text-decoration-none" data-stolovaya-option="employee">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.stolovaya_chooser_employee') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.stolovaya_chooser_employee_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endcan
    </div>

    <div class="mt-4">
        <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
    </div>
</div>

<style>
    .income-type-card { transition: box-shadow .15s ease, transform .15s ease; }
    .income-type-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; transform: translateY(-1px); }
</style>
@endsection
