@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('finance_workspace.stolovaya_employee_receipt_title') }}</h1>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.employee-stolovaya.create') }}">Ещё покупка</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_employee_receipt_employee') }}</dt>
                <dd class="col-sm-8">{{ $purchase->employee->name }}</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_date_label') }}</dt>
                <dd class="col-sm-8">{{ $purchase->food_date->toDateString() }}</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_meal_label') }}</dt>
                <dd class="col-sm-8">{{ $purchase->mealPlan->name_ru }}</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_quantity_label') }}</dt>
                <dd class="col-sm-8">{{ $purchase->quantity }}</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_unit_price_label') }}</dt>
                <dd class="col-sm-8">{{ number_format((float) $purchase->unit_price, 2) }} EGP</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_total_label') }}</dt>
                <dd class="col-sm-8 fw-bold">{{ number_format((float) $purchase->total_amount, 2) }} EGP</dd>

                <dt class="col-sm-4">{{ __('finance_workspace.stolovaya_employee_receipt_revenue_ref') }}</dt>
                <dd class="col-sm-8">{{ $purchase->revenueEntry->reference_number }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
