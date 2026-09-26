@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('finance_workspace.stolovaya_employee_page_title') }}</h1>
            <div class="text-muted">{{ __('finance_workspace.stolovaya_employee_cash_only_hint') }}</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.finance.income.index') }}">Назад</a>
    </div>

    @if($employees->isEmpty())
        <div class="alert alert-warning">{{ __('finance_workspace.stolovaya_employee_no_employees') }}</div>
    @elseif($mealPlans->isEmpty())
        <div class="alert alert-warning">Нет активных планов питания с настроенной ценой.</div>
    @else
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('dashboard.employee-stolovaya.store') }}" id="employee-stolovaya-form">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="employee_user_id">{{ __('finance_workspace.stolovaya_employee_type_label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control mb-1" id="employee-filter" placeholder="Поиск по имени...">
                    <select name="employee_user_id" id="employee_user_id" class="form-select @error('employee_user_id') is-invalid @enderror" required>
                        <option value="">{{ __('finance_workspace.stolovaya_employee_placeholder') }}</option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('employee_user_id') === (string) $employee->id)>{{ $employee->name }}</option>
                        @endforeach
                    </select>
                    @error('employee_user_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="food_date">{{ __('finance_workspace.stolovaya_date_label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" name="food_date" id="food_date" class="form-control @error('food_date') is-invalid @enderror" value="{{ old('food_date', now()->toDateString()) }}" required>
                    @error('food_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="quantity">{{ __('finance_workspace.stolovaya_quantity_label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="number" name="quantity" id="quantity" class="form-control @error('quantity') is-invalid @enderror" min="1" max="20" value="{{ old('quantity', 1) }}" required>
                    @error('quantity')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="meal_plan_id">{{ __('finance_workspace.stolovaya_meal_label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="meal_plan_id" id="meal_plan_id" class="form-select @error('meal_plan_id') is-invalid @enderror" required>
                        <option value="">{{ __('finance_workspace.stolovaya_meal_placeholder') }}</option>
                        @foreach($mealPlans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('meal_plan_id') === (string) $plan->id)>{{ $plan->name_ru }}</option>
                        @endforeach
                    </select>
                    @error('meal_plan_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('finance_workspace.stolovaya_unit_price_label') }}</label>
                    <input class="form-control" id="unit-price-display" value="—" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('finance_workspace.stolovaya_total_label') }}</label>
                    <input class="form-control fw-bold" id="total-display" value="—" readonly>
                </div>
            </div></div>

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Способ оплаты</label>
                    <input class="form-control" value="Наличные" readonly>
                    <div class="form-text">{{ __('finance_workspace.stolovaya_employee_cash_only_hint') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="cash_account_id">Касса</label>
                    <select name="cash_account_id" id="cash_account_id" class="form-select @error('cash_account_id') is-invalid @enderror" required>
                        <option value="">Выберите кассу</option>
                        @foreach($cashAccounts as $account)
                            <option value="{{ $account->id }}" @selected((string) old('cash_account_id') === (string) $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('cash_account_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div></div>

            <div class="d-flex justify-content-end">
                <button class="btn btn-success" id="employee-stolovaya-submit">{{ __('finance_workspace.stolovaya_employee_submit') }}</button>
            </div>
        </form>
    @endif
</div>

@if($employees->isNotEmpty() && $mealPlans->isNotEmpty())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mealPlanSelect = document.getElementById('meal_plan_id');
    const foodDate = document.getElementById('food_date');
    const quantity = document.getElementById('quantity');
    const unitPriceDisplay = document.getElementById('unit-price-display');
    const totalDisplay = document.getElementById('total-display');
    const submit = document.getElementById('employee-stolovaya-submit');

    const money = value => `${Number(value || 0).toFixed(2)} EGP`;

    // Plain-vanilla type-to-filter over the employee <select> — this
    // Dashboard workspace has no existing searchable-select component
    // (unlike Filament's Select::searchable() the payroll picker uses),
    // and a real employee list is small enough that this is the narrowest
    // addition rather than pulling in a JS library.
    const employeeFilter = document.getElementById('employee-filter');
    const employeeSelect = document.getElementById('employee_user_id');
    const employeeOptions = Array.from(employeeSelect.options).map(o => ({ value: o.value, text: o.text }));
    employeeFilter.addEventListener('input', () => {
        const term = employeeFilter.value.trim().toLowerCase();
        const current = employeeSelect.value;
        employeeSelect.innerHTML = '';
        employeeOptions
            .filter(o => o.value === '' || o.text.toLowerCase().includes(term))
            .forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value; opt.text = o.text;
                employeeSelect.appendChild(opt);
            });
        if (employeeOptions.some(o => o.value === current)) employeeSelect.value = current;
    });

    // Server-authoritative preview — display-only. store() independently
    // re-resolves the same FeePrice and never trusts this preview's
    // numbers.
    const preview = async () => {
        unitPriceDisplay.value = '—';
        totalDisplay.value = '—';
        submit.disabled = false;
        if (!mealPlanSelect.value || !foodDate.value || !quantity.value) return;

        const body = new URLSearchParams({
            meal_plan_id: mealPlanSelect.value,
            food_date: foodDate.value,
            quantity: quantity.value,
        });

        const response = await fetch('{{ route('dashboard.employee-stolovaya.price') }}?' + body.toString(), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            totalDisplay.value = 'Тариф не настроен';
            submit.disabled = true;
            return;
        }
        const result = await response.json();
        unitPriceDisplay.value = money(result.unit_price);
        totalDisplay.value = money(result.amount);
    };

    mealPlanSelect.addEventListener('change', preview);
    foodDate.addEventListener('change', preview);
    quantity.addEventListener('change', preview);
    quantity.addEventListener('input', preview);

    if (mealPlanSelect.value) preview();
});
</script>
@endpush
@endif
@endsection
