@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('finance_workspace.stolovaya_page_title') }}</h1>
            <div class="text-muted">{{ $student->full_name }}</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.finance.income.students') }}">Назад</a>
    </div>

    @if(! $year)
        <div class="alert alert-warning">У ученика нет активного зачисления — начисление недоступно.</div>
    @elseif(! $foodFee)
        <div class="alert alert-warning">{{ __('finance_workspace.stolovaya_no_food_fee') }}</div>
    @elseif($mealPlans->isEmpty())
        <div class="alert alert-warning">Нет активных планов питания с настроенной ценой.</div>
    @else
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if(session('existing_invoice_id'))
            <div class="mb-4">
                <a class="btn btn-outline-primary" href="{{ route('dashboard.invoices.show', session('existing_invoice_id')) }}">Открыть существующий счёт</a>
            </div>
        @endif

        <form method="POST" action="{{ route('dashboard.students.stolovaya.store', $student) }}" id="stolovaya-form">
            @csrf
            <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            {{-- Synced to food_date via JS below: a daily meal is priced and
                 due on the same day it is eaten. --}}
            <input type="hidden" name="pricing_date" id="pricing_date">
            <input type="hidden" name="due_date" id="due_date">

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">{{ __('finance_workspace.stolovaya_type_label') }}</label>
                    <input class="form-control" value="{{ __('finance_workspace.stolovaya_type_student') }}" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="food_date">{{ __('finance_workspace.stolovaya_date_label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" name="food_date" id="food_date" class="form-control @error('food_date') is-invalid @enderror" value="{{ old('food_date', now()->toDateString()) }}" required>
                    @error('food_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="quantity">{{ __('finance_workspace.stolovaya_quantity_label') }}</label>
                    {{-- Phase 1: fixed at 1 — Food billing has no quantity
                         concept independent of billable-day-count (see
                         StoreChargeAndCollectRequest::prepareForValidation());
                         a client-supplied quantity for Food is never used. --}}
                    <input type="number" class="form-control" value="1" disabled>
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

            <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                <label class="form-label fw-bold d-block mb-2">{{ __('finance_workspace.stolovaya_settlement_label') }}</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="settlement" id="settlement-paid" value="paid" checked>
                    <label class="form-check-label" for="settlement-paid">{{ __('finance_workspace.stolovaya_settlement_paid_now') }}</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="settlement" id="settlement-unpaid" value="unpaid">
                    <label class="form-check-label" for="settlement-unpaid">{{ __('finance_workspace.stolovaya_settlement_unpaid') }}</label>
                </div>

                <div class="row g-3 mt-2" id="collect-fields">
                    <input type="hidden" name="collect_amount" id="collect_amount" value="0">
                    <div class="col-md-4">
                        <label class="form-label" for="payment_method">Способ оплаты</label>
                        <select name="payment_method" id="payment_method" class="form-select @error('payment_method') is-invalid @enderror">
                            <option value="cash">Наличные</option>
                            <option value="card">Банковская карта</option>
                            <option value="bank">Банковский перевод</option>
                            <option value="instapay">InstaPay</option>
                        </select>
                        @error('payment_method')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="cash_account_id">Касса</label>
                        <select name="cash_account_id" id="cash_account_id" class="form-select @error('cash_account_id') is-invalid @enderror">
                            <option value="">Выберите кассу</option>
                            @foreach($cashAccounts as $account)
                                <option value="{{ $account->id }}" @selected(old('cash_account_id')==$account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div>
                        @error('cash_account_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div></div>

            <div class="d-flex justify-content-end">
                <button class="btn btn-success" id="stolovaya-submit">Начислить</button>
            </div>
        </form>
    @endif
</div>

@if($year && $foodFee && $mealPlans->isNotEmpty())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('stolovaya-form');
    const token = form.querySelector('[name="_token"]').value;
    const foodDate = document.getElementById('food_date');
    const pricingDate = document.getElementById('pricing_date');
    const dueDate = document.getElementById('due_date');
    const mealPlanSelect = document.getElementById('meal_plan_id');
    const unitPriceDisplay = document.getElementById('unit-price-display');
    const totalDisplay = document.getElementById('total-display');
    const submit = document.getElementById('stolovaya-submit');
    const enrollmentModeId = '{{ $student->currentEnrollment?->enrollment_mode_id }}';

    const settlementPaid = document.getElementById('settlement-paid');
    const settlementUnpaid = document.getElementById('settlement-unpaid');
    const collectFields = document.getElementById('collect-fields');
    const collectAmount = document.getElementById('collect_amount');
    let previewAmount = '0';

    const money = value => `${Number(value || 0).toFixed(2)} EGP`;

    const syncSettlement = () => {
        const paid = settlementPaid.checked;
        collectFields.hidden = !paid;
        collectAmount.value = paid ? previewAmount : '0';
    };
    settlementPaid.addEventListener('change', syncSettlement);
    settlementUnpaid.addEventListener('change', syncSettlement);

    const paymentMethod = document.getElementById('payment_method');
    const cashAccountField = document.getElementById('cash_account_id');
    const cashAccountHint = document.getElementById('cash-account-auto-hint');
    const syncCashAccount = () => {
        const canonical = ['cash', 'bank', 'instapay'].includes(paymentMethod.value);
        cashAccountField.disabled = canonical;
        cashAccountHint.classList.toggle('d-none', !canonical);
    };
    paymentMethod.addEventListener('change', syncCashAccount);
    syncCashAccount();

    const syncHiddenDates = () => {
        pricingDate.value = foodDate.value;
        dueDate.value = foodDate.value;
    };

    // Server-authoritative preview — the SAME price() endpoint Charge &
    // Collect's own JS calls, so this can never structurally disagree
    // with what StolovayaController::store() actually charges. Unit
    // price/total shown here are display-only; FeePrice resolution
    // happens again, authoritatively, server-side on submit.
    const preview = async () => {
        syncHiddenDates();
        unitPriceDisplay.value = '—';
        totalDisplay.value = '—';
        submit.disabled = false;
        if (!mealPlanSelect.value || !foodDate.value) return;

        const body = new FormData();
        body.append('_token', token);
        body.append('fee_id', '{{ $foodFee->id }}');
        body.append('quantity', '1');
        body.append('academic_year_id', '{{ $year->id }}');
        body.append('enrollment_mode_id', enrollmentModeId);
        body.append('meal_plan_id', mealPlanSelect.value);
        body.append('food_duration_mode', 'day');
        body.append('food_date', foodDate.value);

        const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {
            method: 'POST', body, headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            totalDisplay.value = 'Тариф не настроен';
            submit.disabled = true;
            return;
        }
        const result = await response.json();
        previewAmount = result.amount;
        unitPriceDisplay.value = money(result.unit_price ?? result.amount);
        totalDisplay.value = money(result.amount);
        syncSettlement();
    };

    mealPlanSelect.addEventListener('change', preview);
    foodDate.addEventListener('change', preview);

    syncSettlement();
    syncHiddenDates();
    if (mealPlanSelect.value) preview();
});
</script>
@endpush
@endif
@endsection
