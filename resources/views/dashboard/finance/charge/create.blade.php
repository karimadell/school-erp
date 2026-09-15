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
            {{-- Flat tariff-selection fields consumed by StoreChargeAndCollectRequest
                 for every NON-Food category — unchanged. --}}
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
                <div class="col-md-2" id="quantity-wrap">
                    <label class="form-label" for="quantity">Количество <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="number" name="quantity" id="quantity" class="form-control" min="1" max="100" value="{{ old('quantity', 1) }}" required>
                </div>
                <div class="col-md-4" id="tariff-wrap" hidden>
                    <label class="form-label" for="tariff-select">Вариант тарифа</label>
                    <select id="tariff-select" class="form-select"></select>
                </div>

                {{-- Existing-student Food purchase corrective pass: shown only
                     when the selected Fee's category is "food". Replaces the
                     generic tariff-variant selector above with the exact same
                     duration-mode controls Quick Registration already offers
                     (day / school week / N teaching days / custom range) —
                     never a second, independently-invented Food UI. --}}
                <div class="col-12 row g-3" id="food-wrap" hidden>
                    <div class="col-md-4">
                        <label class="form-label" for="meal_plan_id">План питания <span class="text-danger" aria-hidden="true">*</span></label>
                        <select name="meal_plan_id" id="meal_plan_id" class="form-select @error('meal_plan_id') is-invalid @enderror">
                            <option value="">Выберите план питания</option>
                            @foreach($mealPlans as $plan)
                                <option value="{{ $plan->id }}" @selected((string) old('meal_plan_id') === (string) $plan->id)>{{ $plan->name_ru }}</option>
                            @endforeach
                        </select>
                        @error('meal_plan_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="food_duration_mode">Режим периода <span class="text-danger" aria-hidden="true">*</span></label>
                        <select name="food_duration_mode" id="food_duration_mode" class="form-select @error('food_duration_mode') is-invalid @enderror">
                            <option value="day" @selected(old('food_duration_mode', 'day') === 'day')>Один день</option>
                            <option value="school_week" @selected(old('food_duration_mode') === 'school_week')>Учебная неделя</option>
                            <option value="teaching_days" @selected(old('food_duration_mode') === 'teaching_days')>N учебных дней</option>
                            <option value="custom_range" @selected(old('food_duration_mode') === 'custom_range')>Произвольный период (С даты — По дату)</option>
                        </select>
                        @error('food_duration_mode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 food-duration-fields" data-mode="day">
                        <label class="form-label" for="food_date">Дата <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="date" name="food_date" id="food_date" value="{{ old('food_date', now()->toDateString()) }}" class="form-control food-field @error('food_date') is-invalid @enderror" data-food-mode="day">
                        @error('food_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 food-duration-fields d-none" data-mode="school_week">
                        <label class="form-label" for="food_week_start">Начало учебной недели <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="date" name="food_week_start" id="food_week_start" value="{{ old('food_week_start') }}" class="form-control food-field @error('food_week_start') is-invalid @enderror" data-food-mode="school_week">
                        @error('food_week_start')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 food-duration-fields d-none row g-2" data-mode="teaching_days">
                        <div class="col-6">
                            <label class="form-label" for="food_start_date">Начало <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="date" name="food_start_date" id="food_start_date" value="{{ old('food_start_date') }}" class="form-control food-field @error('food_start_date') is-invalid @enderror" data-food-mode="teaching_days">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="food_day_count">Кол-во учебных дней <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="number" min="1" name="food_day_count" id="food_day_count" value="{{ old('food_day_count') }}" class="form-control food-field @error('food_day_count') is-invalid @enderror" data-food-mode="teaching_days">
                        </div>
                        @error('food_start_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('food_day_count')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 food-duration-fields d-none row g-2" data-mode="custom_range">
                        <div class="col-6">
                            <label class="form-label" for="food_range_start">С даты <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="date" name="food_range_start" id="food_range_start" value="{{ old('food_range_start') }}" class="form-control food-field @error('food_range_start') is-invalid @enderror" data-food-mode="custom_range">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="food_range_end">По дату <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="date" name="food_range_end" id="food_range_end" value="{{ old('food_range_end') }}" class="form-control food-field @error('food_range_end') is-invalid @enderror" data-food-mode="custom_range">
                        </div>
                        @error('food_range_start')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('food_range_end')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="text-muted">Сумма начисления:</span>
                        <strong id="charge-total" class="fs-5">—</strong>
                    </div>
                    <div class="small text-muted" id="tariff-validity" style="white-space: pre-line;"></div>
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
    const quantityWrap = document.getElementById('quantity-wrap');
    const priceDate = document.getElementById('pricing_date');
    const tariffWrap = document.getElementById('tariff-wrap');
    const tariffSelect = document.getElementById('tariff-select');
    const totalEl = document.getElementById('charge-total');
    const validityEl = document.getElementById('tariff-validity');
    const optIds = ['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'];
    const gradeId = '{{ $student->currentEnrollment?->grade_id }}';
    const modeId = '{{ $student->currentEnrollment?->enrollment_mode_id }}';
    const submit = document.getElementById('charge-submit');

    // Existing-student Food purchase corrective pass — Food UI.
    const foodWrap = document.getElementById('food-wrap');
    const mealPlanSelect = document.getElementById('meal_plan_id');
    const foodModeSelect = document.getElementById('food_duration_mode');

    const cents = value => Math.round((Number(value || 0) + Number.EPSILON) * 100);
    const money = value => `${(cents(value) / 100).toFixed(2)} EGP`;
    // Formats an already-ISO (YYYY-MM-DD) date string from price()'s own
    // coverage_start/coverage_end response as DD/MM/YYYY via plain string
    // splitting — never via `new Date()`, which would risk a
    // timezone-driven off-by-one day against the backend's authoritative,
    // timezone-free date string.
    const formatDMY = value => {
        if (!value) return '';
        const [y, m, d] = value.split('-');
        return `${d}/${m}/${y}`;
    };
    const foodDayWord = count => {
        const mod100 = count % 100;
        if (mod100 >= 11 && mod100 <= 14) return 'учебных дней';
        const mod10 = count % 10;
        if (mod10 === 1) return 'учебный день';
        if (mod10 >= 2 && mod10 <= 4) return 'учебных дня';
        return 'учебных дней';
    };

    const isFood = () => fees[feeSelect.value]?.category === 'food';

    const syncFoodDurationMode = () => {
        const mode = foodModeSelect.value;
        document.querySelectorAll('.food-duration-fields').forEach((group) => {
            const active = group.dataset.mode === mode;
            group.classList.toggle('d-none', !active);
            group.querySelectorAll('input').forEach((input) => { input.disabled = !active; });
        });
    };

    const syncCategoryUi = () => {
        const food = isFood();
        foodWrap.hidden = !food;
        tariffWrap.hidden = true; // re-evaluated by renderTariffs() for non-Food below
        quantityWrap.hidden = food;
        mealPlanSelect.disabled = !food;
        foodModeSelect.disabled = !food;
        qty.disabled = food;
        if (food) {
            qty.value = '1';
            syncFoodDurationMode();
        } else {
            document.querySelectorAll('.food-duration-fields input').forEach((input) => { input.disabled = true; });
        }
    };

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
        if (isFood()) { tariffWrap.hidden = true; return; }
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
        if (isFood()) return; // Food sets option_type/option_value server-side from meal_plan_id.
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
        body.append('academic_year_id', '{{ $year->id }}');
        body.append('grade_id', gradeId);
        body.append('enrollment_mode_id', modeId);
        body.append('registration_date', priceDate.value);

        if (isFood()) {
            body.append('quantity', '1');
            if (mealPlanSelect.value) body.append('meal_plan_id', mealPlanSelect.value);
            const mode = foodModeSelect.value;
            body.append('food_duration_mode', mode);
            document.querySelectorAll(`.food-field[data-food-mode="${mode}"]`).forEach((input) => {
                if (input.value) body.append(input.name, input.value);
            });
        } else {
            body.append('quantity', qty.value || '1');
            const variant = currentVariant();
            if (variant) {
                optIds.forEach((key) => { if (variant[key]) body.append(key, variant[key]); });
            }
        }

        const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {
            method: 'POST', body, headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            // Surface the resolver's own validation message when available
            // (e.g. "Нет тарифа для выбранной зоны") instead of a generic
            // string; never display anything from a non-422 response.
            let message = 'Тариф не настроен.';
            if (response.status === 422) {
                try {
                    const problem = await response.json();
                    const firstError = Object.values(problem.errors || {})[0]?.[0];
                    if (firstError) message = firstError;
                } catch (e) { /* keep the generic fallback */ }
            } else {
                message = 'Не удалось рассчитать тариф. Попробуйте ещё раз.';
            }
            totalEl.textContent = message;
            validityEl.textContent = message;
            submit.disabled = true;
            return;
        }
        const result = await response.json();
        previewCents = Math.round(Number(result.amount) * 100);
        totalEl.textContent = money(result.amount);

        if (result.billable_day_count !== null && result.billable_day_count !== undefined) {
            // Food pricing transparency — the exact same billable_day_count/
            // coverage_start/coverage_end final issuance will charge, never
            // recomputed in JS, only formatted for display (matches Quick
            // Registration's own live preview exactly).
            const days = result.billable_day_count;
            validityEl.textContent = `${days} ${foodDayWord(days)} × ${money(result.unit_price)}\n`
                + `${formatDMY(result.coverage_start)} – ${formatDMY(result.coverage_end)}\n`
                + `Итого: ${money(result.amount)}`;
        } else {
            const fmt = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '';
            validityEl.textContent = result.valid_from
                ? `Действует с ${fmt(result.valid_from)}${result.valid_to ? ` по ${fmt(result.valid_to)}` : ''}` : '';
        }
    };

    feeSelect.addEventListener('change', () => { syncCategoryUi(); renderTariffs(); preview(); });
    tariffSelect.addEventListener('change', preview);
    qty.addEventListener('change', preview);
    priceDate.addEventListener('change', preview);
    mealPlanSelect.addEventListener('change', preview);
    foodModeSelect.addEventListener('change', () => { syncFoodDurationMode(); preview(); });
    document.querySelectorAll('.food-field').forEach((input) => input.addEventListener('change', preview));

    syncCollect();
    syncCategoryUi();
    if (feeSelect.value) { renderTariffs(); preview(); }
});
</script>
@endpush
@endif
@endsection
