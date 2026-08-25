@extends('layouts.dashboard')

{{--
    Thin UI for EmployeePayrollService::pay() — collects the two inputs
    the service needs (cash account + payment method) and posts them to
    SalaryController::pay(), which calls the service directly. No payment
    math or state-transition logic lives here.
--}}

@section('content')
<div class="container py-4" style="max-width: 640px;">

    <h3 class="fw-bold mb-4">{{ __('teacher_salary.pay') }}</h3>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <p class="mb-1"><strong>{{ __('teacher_salary.employee') }}:</strong> {{ $salary->employee_display_name }}</p>
            <p class="mb-1"><strong>{{ __('teacher_salary.month') }}:</strong> {{ $salary->salary_month->format('m.Y') }}</p>
            <p class="mb-0"><strong>{{ __('teacher_salary.net_salary') }}:</strong> {{ number_format($salary->net_salary, 2) }}</p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.salaries.pay.store', $salary) }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">{{ __('teacher_salary.cash_account') }}</label>
            <select name="cash_account_id" class="form-select" required>
                <option value="">—</option>
                @foreach($cashAccounts as $account)
                    <option value="{{ $account->id }}" @selected(old('cash_account_id') == $account->id)>
                        {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">{{ __('teacher_salary.payment_method') }}</label>
            <select name="payment_method" class="form-select" required>
                @foreach(['cash', 'card', 'bank', 'transfer'] as $method)
                    <option value="{{ $method }}" @selected(old('payment_method') === $method)>
                        {{ __('teacher_salary.methods.'.$method) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('teacher_salary.pay') }}?')">
                {{ __('teacher_salary.pay') }}
            </button>
            <a href="{{ route('dashboard.salaries.index') }}" class="btn btn-secondary">
                {{ __('app.cancel') }}
            </a>
        </div>
    </form>
</div>
@endsection
