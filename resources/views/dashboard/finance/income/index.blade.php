@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.income_type_title') }}</h1>
        <p class="text-muted mb-0">{{ __('finance_workspace.income_type_hint') }}</p>
    </div>

    <div class="row g-3">
        {{-- Finance Workspace corrective PR #2 — this card previously had no
             permission gate at all, even though QuickStudentRegistrationController
             (and its create() GET action) requires 'manage invoices' — a
             view-invoices-only role (e.g. reception) could reach Приход and
             click straight into a 403. Gated to match. --}}
        @can('manage invoices')
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
        @endcan

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
            <a href="{{ route('dashboard.finance.income.buffet') }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_buffet') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_buffet_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        {{-- Столовая (Student, Phase 1) — daily meal charges are Student
             Food accounting, gated the same as "Оплата ученика"/"Услуга"
             above (manage invoices), not the view-invoices-only gate the
             Buffet/Donation/Other cards use. --}}
        @can('manage invoices')
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.stolovaya') }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_stolovaya') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_stolovaya_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>
        @endcan

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
        {{-- Finance UX corrective — thin entry point into the EXISTING
             canonical service/fee catalog (FinanceServiceController), never
             a new pricing engine or CRUD. Gated on the exact permission
             that catalog already requires, so this can never lead to a
             403. --}}
        @can('manage fees')
            <a href="{{ route('dashboard.finance.services.index') }}" class="btn btn-outline-secondary">{{ __('finance_workspace.income_service_settings') }}</a>
        @endcan
    </div>
</div>

<style>
    .income-type-card { transition: box-shadow .15s ease, transform .15s ease; }
    .income-type-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; transform: translateY(-1px); }
</style>
@endsection
