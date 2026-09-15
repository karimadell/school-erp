@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.add_service_title') }}</h1>
        <p class="text-muted mb-0">{{ $student->full_name }} · {{ __('finance_workspace.add_service_hint') }}</p>
    </div>

    <div class="row g-3">
        @php
            // Finance Workspace corrective PR #3 — every non-Food tile below
            // routes into the SAME existing Classic Invoice screen (it
            // already lets the accountant select one or several of these
            // services together in one invoice, with full payment-plan and
            // discount support — nothing here re-implements that). Only
            // Питание routes elsewhere, because ChargeAndCollectService is
            // the sole engine that supports Food at all. See
            // FinanceOperationsController::addServiceSelect()'s own
            // docblock for why non-Food cannot safely move onto that engine
            // instead.
            $classicInvoiceUrl = route('dashboard.students.invoices.create', $student);
        @endphp

        <div class="col-md-6 col-xl-4">
            <a href="{{ route('dashboard.students.charge.create', $student) }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 income-type-card">
                    <div class="card-body">
                        <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.add_service_food') }}</div>
                        <div class="text-muted small">{{ __('finance_workspace.add_service_food_hint') }}</div>
                    </div>
                </div>
            </a>
        </div>

        @foreach ([
            'tuition', 'transport', 'uniform', 'extra_classes', 'activity', 'other',
        ] as $category)
            <div class="col-md-6 col-xl-4">
                <a href="{{ $classicInvoiceUrl }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm h-100 income-type-card">
                        <div class="card-body">
                            <div class="fw-semibold fs-5 mb-1">{{ __('finance_workspace.add_service_'.$category) }}</div>
                            <div class="text-muted small">{{ __('finance_workspace.add_service_'.$category.'_hint') }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="mt-4">
        <a href="{{ route('dashboard.students.finance', $student) }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
    </div>
</div>

<style>
    .income-type-card { transition: box-shadow .15s ease, transform .15s ease; }
    .income-type-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; transform: translateY(-1px); }
</style>
@endsection
