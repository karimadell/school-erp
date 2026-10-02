@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.income_type_title') }}</h1>
        <p class="text-muted mb-0">{{ __('finance_workspace.income_type_hint') }}</p>
    </div>

    {{-- PR 3 — exactly six operational cards in two sections. Every card is
         gated on the permission its DESTINATION actually requires, so no
         role sees a card that ends in a 403; server-side authorization at
         each destination stays authoritative. --}}
    @canany(['manage invoices', 'manage employee stolovaya'])
    <h2 class="h6 text-uppercase text-muted mb-3">{{ __('finance_workspace.income_section_students') }}</h2>
    <div class="row g-3 mb-4" data-income-section="students">
        @can('manage invoices')
            {{-- Оплата ученика — ?context=payment on the canonical student
                 search, whose primary row action is Unified Collection
                 «Принять оплату» (FinanceOperationsController::students()). --}}
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('dashboard.finance.income.students', ['context' => 'payment']) }}" class="text-decoration-none" data-income-card="payment">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_payment') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.income_type_payment_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>

            {{-- Новый ученик / Новый учебный год — the existing Quick
                 Registration flow (new student, or its «Существующий ученик»
                 tab for a returning student); 'manage invoices' is what
                 QuickStudentRegistrationController requires. --}}
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('dashboard.quick-registration.create') }}" class="text-decoration-none" data-income-card="registration">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_registration') }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.income_type_registration_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endcan

        {{-- Столовая — one card for both sides; IncomeEntryController::
             stolovaya() opens a chooser or goes straight to the only side the
             actor may use. The Student (Invoice/Payment) and Employee
             (StaffFoodPurchase/RevenueEntry) engines stay entirely separate. --}}
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.stolovaya') }}" class="text-decoration-none" data-income-card="stolovaya">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_stolovaya') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_stolovaya_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    @endcanany

    {{-- School income — all three end in RevenueEntryController::create(),
         which authorizes RevenueEntryPolicy::create ('manage revenues'). --}}
    @can('create', \App\Models\RevenueEntry::class)
    <h2 class="h6 text-uppercase text-muted mb-3">{{ __('finance_workspace.income_section_school') }}</h2>
    <div class="row g-3" data-income-section="school">
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.buffet') }}" class="text-decoration-none" data-income-card="buffet">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_buffet') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_buffet_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.donation') }}" class="text-decoration-none" data-income-card="donation">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_donation') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_donation_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.finance.income.other') }}" class="text-decoration-none" data-income-card="other">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.income_type_other') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.income_type_other_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    @endcan

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
