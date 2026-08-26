@extends('layouts.dashboard')
@section('content')
@php
    $feeOptions = $fees->map(fn ($fee) => [
        'id' => $fee->id,
        'name' => $fee->name_ru,
        'category' => $fee->category,
        'variants' => $fee->prices->map(fn ($price) => [
            'grade_group' => $price->grade_group,
            'payment_period' => $price->payment_period,
            'size' => $price->size,
            'item' => $price->item,
            'option_type' => $price->option_type,
            'option_value' => $price->option_value,
            'label' => collect([$price->grade_group, $price->payment_period, $price->item, $price->size, $price->option_value])->filter()->implode(' · ') ?: 'Основной тариф',
        ])->values(),
    ])->keyBy('id');
@endphp
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Начислить и принять оплату</h1>
            <div class="text-muted">{{ $student->full_name }}</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.students.finance', $student) }}">Назад</a>
    </div>

    @if(! $year)
        <div class="alert alert-warning">У ученика нет активного зачисления — начисление недоступно.</div>
    @else
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if(session('existing_invoice_id'))
            <div class="mb-4">
                <a class="btn btn-outline-primary" href="{{ route('dashboard.invoices.show', session('existing_invoice_id')) }}">Открыть существующий счёт</a>
            </div>
        @endif

        <form method="POST" action="{{ route('dashboard.students.charge.store', $student) }}" id="charge-form">
            @csrf
            <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            {{-- Flat tariff-selection fields consumed by StoreChargeAndCollectRequest --}}
            <input type="hidden" name="grade_group" id="opt-grade_group" value="{{ old('grade_group') }}">
            <input type="hidden" name="payment_period" id="opt-payment_period" value="{{ old('payment_period') }}">
            <input type="hidden" name="size" id="opt-size" value="{{ old('size') }}">
            <input type="hidden" name="item" id="opt-item" value="{{ old('item') }}">
            <input type="hidden" name="option_type" id="opt-option_type" value="{{ old('option_type') }}">
            <input type="hidden" name="option_value" id="opt-option_value" value="{{ old('option_value') }}">
            <input type="hidden" name="first_last_month" id="opt-first_last_month" value="">

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Учебный год</label>
                    <input class="form-control" value="{{ $year->name }}" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pricing_date">Дата начисления</label>
                    <input type="date" name="pricing_date" id="pricing_date" class="form-control" value="{{ old('pricing_date', now()->toDateString()) }}" required>
                    <div class="form-text">По этой дате система выбирает действующий тариф.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="due_date">Срок оплаты</label>
                    <input type="date" name="due_date" id="due_date" class="form-control" value="{{ old('due_date', $year->end_date?->format('Y-m-d')) }}" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Примечание</label>
                    <input name="notes" id="notes" class="form-control" maxlength="1000" value="{{ old('notes') }}">
                </div>
            </div></div>

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="fee-select">Услуга <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="fee_id" id="fee-select" class="form-select @error('fee_id') is-invalid @enderror" required>
                        <option value="">Выберите услугу</option>
                        @foreach($fees as $fee)
                            <option value="{{ $fee->id }}" @selected(old('fee_id')==$fee->id)>{{ $fee->name_ru }}</option>
                        @endforeach
                    </select>
                    @error('fee_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="quantity">Количество <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="number" name="quantity" id="quantity" class="form-control" min="1" max="100" value="{{ old('quantity', 1) }}" required>
                </div>
                <div class="col-md-4" id="tariff-wrap" hidden>
                    <label class="form-label" for="tariff-select">Вариант тарифа</label>
                    <select id="tariff-select" class="form-select"></select>
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="text-muted">Сумма начисления:</span>
                        <strong id="charge-total" class="fs-5">—</strong>
                    </div>
                    <div class="small text-muted" id="tariff-validity"></div>
                </div>
            </div></div>

            <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="collect-toggle" @checked((float) old('collect_amount') > 0)>
                    <label class="form-check-label fw-bold" for="collect-toggle">Принять оплату сейчас</label>
                </div>
                <div class="row g-3" id="collect-fields" hidden>
                    <div class="col-md-4">
                        <label class="form-label" for="collect_amount">Сумма оплаты, EGP</label>
                        <input type="number" step="0.01" min="0" name="collect_amount" id="collect_amount" class="form-control @error('collect_amount') is-invalid @enderror" value="{{ old('collect_amount', '0') }}">
                        @error('collect_amount')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <button type="button" class="btn btn-link btn-sm px-0" id="collect-full">Оплатить полностью</button>
                    </div>
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
                <div class="small text-muted mt-2">Оставьте оплату выключенной, чтобы только начислить счёт без приёма денег.</div>
            </div></div>

            <div class="d-flex justify-content-end">
                <button class="btn btn-success" id="charge-submit">Начислить</button>
            </div>
        </form>
    @endif
</div>

@if($year)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const fees = @json($feeOptions);
    const form = document.getElementById('charge-form');
    const token = form.querySelector('[name="_token"]').value;
    const feeSelect = document.getElementById('fee-select');
    const qty = document.getElementById('quantity');
    const priceDate = document.getElementById('pricing_date');
    const tariffWrap = document.getElementById('tariff-wrap');
    const tariffSelect = document.getElementById('tariff-select');
    const totalEl = document.getElementById('charge-total');
    const validityEl = document.getElementById('tariff-validity');
    const optIds = ['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'];
    const gradeId = '{{ $student->currentEnrollment?->grade_id }}';
    const modeId = '{{ $student->currentEnrollment?->enrollment_mode_id }}';
    const submit = document.getElementById('charge-submit');

    // Collection toggle wiring.
    const collectToggle = document.getElementById('collect-toggle');
    const collectFields = document.getElementById('collect-fields');
    const collectAmount = document.getElementById('collect_amount');
    let previewCents = 0;

    const syncCollect = () => {
        collectFields.hidden = !collectToggle.checked;
        if (!collectToggle.checked) collectAmount.value = '0';
    };
    collectToggle.addEventListener('change', syncCollect);
    document.getElementById('collect-full').addEventListener('click', () => {
        collectAmount.value = (previewCents / 100).toFixed(2);
    });

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

    const currentVariant = () => {
        const fee = fees[feeSelect.value];
        if (!fee || !fee.variants.length) return null;
        return fee.variants[Number(tariffSelect.value) || 0] || null;
    };

    const renderTariffs = () => {
        const fee = fees[feeSelect.value];
        tariffSelect.innerHTML = '';
        if (fee && fee.variants.length) {
            fee.variants.forEach((variant, index) => {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = variant.label;
                tariffSelect.appendChild(option);
            });
            tariffWrap.hidden = false;
        } else {
            tariffWrap.hidden = true;
        }
    };

    const applyOptionInputs = () => {
        const variant = currentVariant();
        optIds.forEach((key) => {
            document.getElementById('opt-' + key).value = variant ? (variant[key] || '') : '';
        });
    };

    const preview = async () => {
        applyOptionInputs();
        previewCents = 0;
        totalEl.textContent = '—';
        validityEl.textContent = '';
        submit.disabled = false;
        if (!feeSelect.value) return;

        const body = new FormData();
        body.append('_token', token);
        body.append('fee_id', feeSelect.value);
        body.append('quantity', qty.value || '1');
        body.append('academic_year_id', '{{ $year->id }}');
        body.append('grade_id', gradeId);
        body.append('enrollment_mode_id', modeId);
        body.append('registration_date', priceDate.value);
        const variant = currentVariant();
        if (variant) {
            optIds.forEach((key) => { if (variant[key]) body.append(key, variant[key]); });
        }

        const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {
            method: 'POST', body, headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            totalEl.textContent = 'Тариф не настроен';
            validityEl.textContent = 'На выбранную дату тариф не настроен.';
            submit.disabled = true;
            return;
        }
        const result = await response.json();
        previewCents = Math.round(Number(result.amount) * 100);
        totalEl.textContent = Number(result.amount).toFixed(2) + ' EGP';
        const fmt = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '';
        validityEl.textContent = result.valid_from
            ? `Действует с ${fmt(result.valid_from)}${result.valid_to ? ` по ${fmt(result.valid_to)}` : ''}` : '';
    };

    feeSelect.addEventListener('change', () => { renderTariffs(); preview(); });
    tariffSelect.addEventListener('change', preview);
    qty.addEventListener('change', preview);
    priceDate.addEventListener('change', preview);

    syncCollect();
    if (feeSelect.value) { renderTariffs(); preview(); }
});
</script>
@endpush
@endif
@endsection
