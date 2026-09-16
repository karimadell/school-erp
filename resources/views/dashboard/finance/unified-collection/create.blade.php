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
    $uniformOptions = $uniformProducts->map(fn ($product) => [
        'id' => $product->id, 'label' => trim(($product->name_ru ?? '').' · '.($product->size ?? '')),
    ]);
@endphp
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">Приём оплаты</h1>
            <div class="text-muted">{{ $student->full_name }} · ID {{ $student->id }} · {{ $student->phone }}</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.students.finance', $student) }}">Назад</a>
    </div>

    @if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-md-3"><span class="text-muted small">Класс</span><div class="fw-semibold">{{ $enrollment?->schoolClass?->name ?: '—' }}</div></div>
            <div class="col-md-3"><span class="text-muted small">Учебный год</span><div class="fw-semibold">Учебный год: {{ $year->name }}</div></div>
            <div class="col-md-6 text-md-end">
                <div class="row g-2">
                    <div class="col-3"><div class="text-muted small">Начислено</div><div class="fw-semibold">{{ $yearFigures['invoiced'] ?? '0.00' }}</div></div>
                    <div class="col-3"><div class="text-muted small">Оплачено</div><div class="fw-semibold">{{ $yearFigures['paid'] ?? '0.00' }}</div></div>
                    <div class="col-3"><div class="text-muted small">Задолженность</div><div class="fw-semibold text-danger">{{ $yearFigures['remaining'] ?? '0.00' }}</div></div>
                    <div class="col-3"><div class="text-muted small">Просрочено</div><div class="fw-semibold">{{ $yearFigures['overdue'] ?? '0.00' }}</div></div>
                </div>
            </div>
        </div>
    </div></div>

    <form method="POST" action="{{ route('dashboard.students.unified-collection.store', $student) }}" id="collection-form">
        @csrf
        <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
        <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
        <div id="new-services-fields"></div>

        <h2 class="h5 mb-3">Что оплачивает родитель?</h2>

        {{-- Текущая задолженность --}}
        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h3 class="h6 mb-3">Текущая задолженность</h3>
            @if($obligations->isEmpty())
                <div class="text-muted">За выбранный учебный год задолженности нет.</div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Услуга</th><th>Задолженность</th><th style="width:220px">Получить сейчас</th><th>Остаток</th></tr></thead>
                        <tbody>
                        @foreach($obligations as $i => $row)
                            <tr>
                                <td>
                                    {{ $row['label'] }}
                                    @if($row['overdue'])<span class="badge bg-danger ms-1">Просрочено</span>@endif
                                </td>
                                <td>{{ $row['remaining'] }}</td>
                                <td>
                                    @if($row['submittable'])
                                        <input type="hidden" name="existing_obligations[{{ $i }}][invoice_id]" value="{{ $row['invoice_id'] }}">
                                        @if($row['invoice_item_id'])
                                            <input type="hidden" name="existing_obligations[{{ $i }}][allocations][0][invoice_item_id]" value="{{ $row['invoice_item_id'] }}">
                                            <input type="hidden" class="obligation-alloc-amount" id="obligation-alloc-{{ $i }}" name="existing_obligations[{{ $i }}][allocations][0][amount]" value="0.00">
                                        @endif
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" min="0" max="{{ $row['remaining'] }}"
                                                class="form-control obligation-amount" data-row="{{ $i }}"
                                                name="existing_obligations[{{ $i }}][receive_now_amount]" value="0.00">
                                            <button type="button" class="btn btn-outline-secondary obligation-full" data-row="{{ $i }}" data-remaining="{{ $row['remaining'] }}">Полностью</button>
                                        </div>
                                    @else
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('dashboard.invoices.payments.create', $row['invoice_id']) }}">Оплатить на странице счёта</a>
                                    @endif
                                </td>
                                <td class="obligation-remaining-after" data-row="{{ $i }}" data-remaining="{{ $row['remaining'] }}">{{ $row['remaining'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div></div>

        {{-- Добавить услугу --}}
        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h3 class="h6 mb-3">Добавить услугу</h3>
            @if(! $canChargeNewServices)
                <div class="text-muted">Начисление новых услуг недоступно: у ученика нет активного зачисления на текущий учебный год.</div>
            @else
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Услуга</label>
                        <select id="ns-fee" class="form-select">
                            <option value="">Выберите услугу</option>
                            @foreach($fees as $fee)
                                <option value="{{ $fee->id }}">{{ $fee->name_ru }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2" id="ns-quantity-wrap">
                        <label class="form-label">Количество</label>
                        <input type="number" min="1" max="100" id="ns-quantity" class="form-control" value="1">
                    </div>
                    <div class="col-md-5" id="ns-tariff-wrap" hidden>
                        <label class="form-label">Вариант тарифа</label>
                        <select id="ns-tariff" class="form-select"></select>
                    </div>

                    <div class="col-12 row g-3" id="ns-transport-wrap" hidden>
                        <div class="col-md-6">
                            <label class="form-label">Зона тарифа</label>
                            <select id="ns-transport-area" class="form-select">
                                <option value="">Выберите зону</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Маршрут</label>
                            <select id="ns-transport-route" class="form-select">
                                <option value="">Выберите маршрут</option>
                                @foreach($transportRoutes as $route)
                                    <option value="{{ $route->id }}">{{ $route->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-12 row g-3" id="ns-food-wrap" hidden>
                        <div class="col-md-4">
                            <label class="form-label">План питания</label>
                            <select id="ns-meal-plan" class="form-select">
                                <option value="">Выберите план питания</option>
                                @foreach($mealPlans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name_ru }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Режим периода</label>
                            <select id="ns-food-mode" class="form-select">
                                <option value="day">Один день</option>
                                <option value="school_week">Учебная неделя</option>
                                <option value="teaching_days">N учебных дней</option>
                                <option value="custom_range">С даты — По дату</option>
                            </select>
                        </div>
                        <div class="col-md-4 ns-food-fields" data-mode="day">
                            <label class="form-label">Дата</label>
                            <input type="date" id="ns-food-date" class="form-control ns-food-field" data-food-mode="day" value="{{ now()->toDateString() }}">
                        </div>
                        <div class="col-md-4 ns-food-fields d-none" data-mode="school_week">
                            <label class="form-label">Начало учебной недели</label>
                            <input type="date" id="ns-food-week-start" class="form-control ns-food-field" data-food-mode="school_week">
                        </div>
                        <div class="col-md-4 ns-food-fields d-none row g-2" data-mode="teaching_days">
                            <div class="col-6"><label class="form-label">Начало</label><input type="date" id="ns-food-start-date" class="form-control ns-food-field" data-food-mode="teaching_days"></div>
                            <div class="col-6"><label class="form-label">Кол-во дней</label><input type="number" min="1" id="ns-food-day-count" class="form-control ns-food-field" data-food-mode="teaching_days"></div>
                        </div>
                        <div class="col-md-6 ns-food-fields d-none row g-2" data-mode="custom_range">
                            <div class="col-6"><label class="form-label">С даты</label><input type="date" id="ns-food-range-start" class="form-control ns-food-field" data-food-mode="custom_range"></div>
                            <div class="col-6"><label class="form-label">По дату</label><input type="date" id="ns-food-range-end" class="form-control ns-food-field" data-food-mode="custom_range"></div>
                        </div>
                    </div>

                    <div class="col-12" id="ns-uniform-wrap" hidden>
                        <label class="form-label">Изделия и размеры</label>
                        <div id="ns-uniform-rows"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="ns-uniform-add">+ Добавить изделие</button>
                    </div>

                    <div class="col-md-4">
                        <div class="text-muted small">Начислено по услуге</div>
                        <div class="fw-semibold fs-5" id="ns-preview">—</div>
                        <div class="small text-muted" id="ns-validity" style="white-space:pre-line"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Получить сейчас</label>
                        <input type="number" step="0.01" min="0.01" id="ns-receive-now" class="form-control">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" id="ns-add-btn" disabled>Добавить в список</button>
                    </div>
                </div>

                <div class="mt-3" id="new-services-list"></div>
            @endif
        </div></div>

        @if(! $enrollment && $canRegisterAnnually)
            <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="ar-toggle">
                    <label class="form-check-label fw-bold" for="ar-toggle">Зачисление на {{ $year->name }}</label>
                </div>
                <div class="row g-3" id="ar-fields" hidden>
                    <div class="col-md-3">
                        <label class="form-label">Режим обучения</label>
                        <select name="annual_registration[enrollment_mode_id]" id="ar-mode" class="form-select" disabled>
                            <option value="">Выберите</option>
                            @foreach($enrollmentModes as $mode)<option value="{{ $mode->id }}">{{ $mode->name_ru }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ступень</label>
                        <select id="ar-stage" class="form-select" disabled>
                            <option value="">Выберите</option>
                            @foreach($structureStages as $stage)<option value="{{ $stage['id'] }}">{{ $stage['name'] }}</option>@endforeach
                        </select>
                        <input type="hidden" name="annual_registration[stage_id]" id="ar-stage-hidden">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Класс (параллель)</label>
                        <select id="ar-grade" class="form-select" disabled>
                            <option value="">Выберите ступень</option>
                        </select>
                        <input type="hidden" name="annual_registration[grade_id]" id="ar-grade-hidden">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Класс (группа)</label>
                        <select name="annual_registration[class_id]" id="ar-class" class="form-select" disabled>
                            <option value="">Выберите параллель</option>
                        </select>
                    </div>
                </div>
            </div></div>
        @endif

        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h3 class="h6 mb-3">Итого к получению</h3>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <div class="text-muted small">К получению сейчас</div>
                    <div class="fs-4 fw-bold" id="collection-total">0.00</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="payment_method">Способ оплаты</label>
                    <select name="payment_method" id="payment_method" class="form-select">
                        <option value="cash">Наличные</option>
                        <option value="card">Банковская карта</option>
                        <option value="bank">Банковский перевод</option>
                        <option value="instapay">InstaPay</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="cash_account_id">Касса / счёт</label>
                    <select name="cash_account_id" id="cash_account_id" class="form-select">
                        <option value="">Выберите</option>
                        @foreach($cashAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">Примечание</label>
                    <input name="notes" id="notes" class="form-control" maxlength="1000">
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-success btn-lg" id="collection-submit">Принять оплату</button>
            </div>
        </div></div>
    </form>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const fees = @json($feeOptions);
    const uniformOptions = @json($uniformOptions);
    const priceUrl = '{{ route('dashboard.quick-registration.price') }}';
    const token = document.querySelector('#collection-form input[name="_token"]').value;
    const gradeId = '{{ $enrollment?->grade_id }}';
    const modeId = '{{ $enrollment?->enrollment_mode_id }}';
    const academicYearId = '{{ $year->id }}';
    const cents = value => Math.round((Number(value || 0) + Number.EPSILON) * 100);
    const money = value => (cents(value) / 100).toFixed(2);

    // ----- Итого / obligation row sync -----
    const recalcTotal = () => {
        let total = 0;
        document.querySelectorAll('.obligation-amount').forEach((input) => {
            total += cents(input.value);
            const after = document.querySelector(`.obligation-remaining-after[data-row="${input.dataset.row}"]`);
            if (after) after.textContent = money(Number(after.dataset.remaining) - Number(input.value || 0));
        });
        document.querySelectorAll('#new-services-list [data-receive-now]').forEach((el) => {
            total += cents(el.dataset.receiveNow);
        });
        document.getElementById('collection-total').textContent = money(total / 100);
    };
    document.querySelectorAll('.obligation-amount').forEach((input) => {
        input.addEventListener('input', () => {
            const alloc = document.getElementById('obligation-alloc-' + input.dataset.row);
            if (alloc) alloc.value = input.value || '0.00';
            recalcTotal();
        });
    });
    document.querySelectorAll('.obligation-full').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.querySelector(`.obligation-amount[data-row="${btn.dataset.row}"]`);
            input.value = btn.dataset.remaining;
            input.dispatchEvent(new Event('input'));
        });
    });

    // ----- Cash account canonical UX (mirrors charge/create.blade.php) -----
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

    // ----- Добавить услугу -----
    const feeSelect = document.getElementById('ns-fee');
    if (feeSelect) {
        const qty = document.getElementById('ns-quantity');
        const qtyWrap = document.getElementById('ns-quantity-wrap');
        const tariffWrap = document.getElementById('ns-tariff-wrap');
        const tariffSelect = document.getElementById('ns-tariff');
        const transportWrap = document.getElementById('ns-transport-wrap');
        const transportArea = document.getElementById('ns-transport-area');
        const transportRoute = document.getElementById('ns-transport-route');
        const foodWrap = document.getElementById('ns-food-wrap');
        const mealPlan = document.getElementById('ns-meal-plan');
        const foodMode = document.getElementById('ns-food-mode');
        const uniformWrap = document.getElementById('ns-uniform-wrap');
        const uniformRows = document.getElementById('ns-uniform-rows');
        const preview = document.getElementById('ns-preview');
        const validity = document.getElementById('ns-validity');
        const receiveNow = document.getElementById('ns-receive-now');
        const addBtn = document.getElementById('ns-add-btn');
        const list = document.getElementById('new-services-list');
        const hiddenFields = document.getElementById('new-services-fields');
        let previewAmount = null;
        let entryIndex = 0;

        const category = () => fees[feeSelect.value]?.category;
        const isFood = () => category() === 'food';
        const isTransport = () => category() === 'transport';
        const isUniform = () => category() === 'uniform';

        const syncFoodMode = () => {
            document.querySelectorAll('.ns-food-fields').forEach((group) => {
                const active = group.dataset.mode === foodMode.value;
                group.classList.toggle('d-none', !active);
            });
        };

        const addUniformRow = () => {
            const row = document.createElement('div');
            row.className = 'row g-2 mb-2 ns-uniform-row';
            const options = uniformOptions.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
            row.innerHTML = `
                <div class="col-7"><select class="form-select form-select-sm ns-uniform-product"><option value="">Выберите изделие</option>${options}</select></div>
                <div class="col-3"><input type="number" min="1" value="1" class="form-control form-control-sm ns-uniform-qty"></div>
                <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger ns-uniform-remove">×</button></div>`;
            row.querySelector('.ns-uniform-remove').addEventListener('click', () => { row.remove(); runPreview(); });
            row.querySelector('.ns-uniform-product').addEventListener('change', runPreview);
            row.querySelector('.ns-uniform-qty').addEventListener('change', runPreview);
            uniformRows.appendChild(row);
        };
        document.getElementById('ns-uniform-add')?.addEventListener('click', addUniformRow);

        const syncCategoryUi = () => {
            const food = isFood(), transport = isTransport(), uniform = isUniform();
            foodWrap.hidden = !food;
            transportWrap.hidden = !transport;
            uniformWrap.hidden = !uniform;
            qtyWrap.hidden = food || uniform;
            tariffWrap.hidden = true;
            if (food) { syncFoodMode(); }
            if (uniform && uniformRows.children.length === 0) { addUniformRow(); }
        };

        const currentVariant = () => {
            const fee = fees[feeSelect.value];
            if (!fee || !fee.variants.length) return null;
            return fee.variants[Number(tariffSelect.value) || 0] || null;
        };

        const renderTariffs = () => {
            if (isFood() || isUniform()) { tariffWrap.hidden = true; return; }
            const fee = fees[feeSelect.value];
            tariffSelect.innerHTML = '';
            if (fee && fee.variants.length) {
                fee.variants.forEach((variant, index) => {
                    const option = document.createElement('option');
                    option.value = index; option.textContent = variant.label;
                    tariffSelect.appendChild(option);
                });
                tariffWrap.hidden = false;
            } else { tariffWrap.hidden = true; }
            if (isTransport()) {
                const zones = [...new Set(fee?.variants.map(v => v.option_value).filter(Boolean))];
                transportArea.innerHTML = '<option value="">Выберите зону</option>' + zones.map(z => `<option value="${z}">${z}</option>`).join('');
            }
        };

        const runPreview = async () => {
            addBtn.disabled = true;
            previewAmount = null;
            preview.textContent = '—';
            validity.textContent = '';
            if (!feeSelect.value || isUniform()) {
                if (isUniform() && feeSelect.value) { addBtn.disabled = !receiveNow.value; preview.textContent = 'По каждому изделию — см. тариф формы'; }
                return;
            }

            const body = new FormData();
            body.append('_token', token);
            body.append('fee_id', feeSelect.value);
            body.append('academic_year_id', academicYearId);
            body.append('grade_id', gradeId);
            body.append('enrollment_mode_id', modeId);
            body.append('registration_date', '{{ now()->toDateString() }}');

            if (isFood()) {
                body.append('quantity', '1');
                if (mealPlan.value) body.append('meal_plan_id', mealPlan.value);
                body.append('food_duration_mode', foodMode.value);
                document.querySelectorAll(`.ns-food-field[data-food-mode="${foodMode.value}"]`).forEach((input) => {
                    if (input.value) body.append(input.name || input.id.replace('ns-food-', 'food_').replaceAll('-', '_'), input.value);
                });
            } else {
                body.append('quantity', qty.value || '1');
                if (isTransport()) {
                    if (transportArea.value) body.append('transport_area', transportArea.value);
                } else {
                    const variant = currentVariant();
                    if (variant) {
                        ['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'].forEach((key) => {
                            if (variant[key]) body.append(key, variant[key]);
                        });
                    }
                }
            }

            const response = await fetch(priceUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                let message = 'Тариф не настроен.';
                if (response.status === 422) {
                    try { const problem = await response.json(); message = Object.values(problem.errors || {})[0]?.[0] || message; } catch (e) {}
                }
                preview.textContent = message;
                return;
            }
            const result = await response.json();
            previewAmount = result.amount;
            preview.textContent = money(result.amount);
            addBtn.disabled = !receiveNow.value;
        };

        feeSelect.addEventListener('change', () => { syncCategoryUi(); renderTariffs(); runPreview(); });
        tariffSelect.addEventListener('change', runPreview);
        qty.addEventListener('change', runPreview);
        transportArea.addEventListener('change', () => { runPreview(); });
        transportRoute.addEventListener('change', () => {});
        mealPlan.addEventListener('change', runPreview);
        foodMode.addEventListener('change', () => { syncFoodMode(); runPreview(); });
        document.querySelectorAll('.ns-food-field').forEach((input) => input.addEventListener('change', runPreview));
        receiveNow.addEventListener('input', () => { addBtn.disabled = !receiveNow.value || (!previewAmount && !isUniform()); });

        addBtn.addEventListener('click', () => {
            const fee = fees[feeSelect.value];
            if (!fee || !receiveNow.value) return;
            const index = entryIndex++;
            const set = (name, value) => {
                if (value === undefined || value === null || value === '') return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `new_services[${index}][${name}]`; input.value = value;
                hiddenFields.appendChild(input);
            };
            set('fee_id', feeSelect.value);
            set('receive_now_amount', receiveNow.value);

            let summary = fee.name;
            if (isFood()) {
                set('meal_plan_id', mealPlan.value);
                set('food_duration_mode', foodMode.value);
                document.querySelectorAll(`.ns-food-field[data-food-mode="${foodMode.value}"]`).forEach((input) => {
                    if (input.value) set(input.id.replace('ns-food-', 'food_').replaceAll('-', '_'), input.value);
                });
            } else if (isTransport()) {
                set('quantity', qty.value);
                set('transport_area', transportArea.value);
                set('transport_route_id', transportRoute.value);
            } else if (isUniform()) {
                let itemIndex = 0;
                document.querySelectorAll('.ns-uniform-row').forEach((row) => {
                    const productId = row.querySelector('.ns-uniform-product').value;
                    const rowQty = row.querySelector('.ns-uniform-qty').value;
                    if (!productId) return;
                    set(`uniform_items[${itemIndex}][uniform_product_id]`, productId);
                    set(`uniform_items[${itemIndex}][quantity]`, rowQty);
                    itemIndex++;
                });
            } else {
                set('quantity', qty.value);
                const variant = currentVariant();
                if (variant) {
                    ['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'].forEach((key) => {
                        if (variant[key]) set(key, variant[key]);
                    });
                }
            }

            const row = document.createElement('div');
            row.className = 'd-flex justify-content-between align-items-center border rounded p-2 mb-2';
            row.dataset.receiveNow = receiveNow.value;
            row.setAttribute('data-receive-now', receiveNow.value);
            row.innerHTML = `<span>${summary} — получено ${money(receiveNow.value)}</span>`;
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button'; removeBtn.className = 'btn btn-sm btn-outline-danger'; removeBtn.textContent = 'Убрать';
            removeBtn.addEventListener('click', () => {
                hiddenFields.querySelectorAll(`input[name^="new_services[${index}]"]`).forEach((el) => el.remove());
                row.remove();
                recalcTotal();
            });
            row.appendChild(removeBtn);
            list.appendChild(row);

            receiveNow.value = '';
            addBtn.disabled = true;
            recalcTotal();
        });

        syncCategoryUi();
    }

    // ----- Annual registration panel -----
    const arToggle = document.getElementById('ar-toggle');
    if (arToggle) {
        const arFields = document.getElementById('ar-fields');
        const arStage = document.getElementById('ar-stage');
        const arStageHidden = document.getElementById('ar-stage-hidden');
        const arGrade = document.getElementById('ar-grade');
        const arGradeHidden = document.getElementById('ar-grade-hidden');
        const arClass = document.getElementById('ar-class');
        const arMode = document.getElementById('ar-mode');
        const stages = @json($structureStages);

        const setDisabled = (disabled) => {
            [arStage, arGrade, arClass, arMode].forEach((el) => { el.disabled = disabled; });
        };
        arToggle.addEventListener('change', () => { arFields.hidden = !arToggle.checked; setDisabled(!arToggle.checked); });

        arStage.addEventListener('change', () => {
            arStageHidden.value = arStage.value;
            const stage = stages.find(s => String(s.id) === arStage.value);
            arGrade.innerHTML = '<option value="">Выберите параллель</option>' + (stage ? stage.grades.map(g => `<option value="${g.id}">${g.name}</option>`).join('') : '');
            arClass.innerHTML = '<option value="">Выберите группу</option>';
            arGradeHidden.value = '';
        });
        arGrade.addEventListener('change', () => {
            arGradeHidden.value = arGrade.value;
            const stage = stages.find(s => String(s.id) === arStage.value);
            const grade = stage?.grades.find(g => String(g.id) === arGrade.value);
            arClass.innerHTML = '<option value="">Выберите группу</option>' + (grade ? grade.classes.map(c => `<option value="${c.id}">${c.name}</option>`).join('') : '');
        });
    }

    // Submit-button disable-on-click (UX only — the real duplicate-money
    // protection is FinanceCollectionService's own server-side idempotency).
    document.getElementById('collection-form').addEventListener('submit', function () {
        document.getElementById('collection-submit').disabled = true;
    });

    recalcTotal();
});
</script>
@endpush
@endsection
