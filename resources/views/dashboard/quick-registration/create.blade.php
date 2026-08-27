@extends('layouts.dashboard')

@section('content')
@php
    $tuitionCategories = ['tuition', 'tuition_regular', 'tuition_family', 'tuition_external'];
    $groups = [
        'registration' => ['title' => 'Регистрационный взнос', 'fees' => $fees->where('category', 'registration')],
        'tuition' => ['title' => 'Обучение', 'fees' => $fees->whereIn('category', $tuitionCategories)],
        'transport' => ['title' => 'Транспорт', 'fees' => $fees->where('category', 'transport')],
        'food' => ['title' => 'Питание', 'fees' => $fees->where('category', 'food')],
        'uniform' => ['title' => 'Школьная форма', 'fees' => $fees->where('category', 'uniform')],
        'other' => ['title' => 'Дополнительные услуги', 'fees' => $fees->whereIn('category', ['books', 'extra_classes', 'activity', 'other'])],
    ];
    $oldServices = collect(old('services', []))->keyBy(fn ($service) => (string) ($service['fee_id'] ?? ''));
    $configurationReady = $academicYears->isNotEmpty() && $modes->isNotEmpty() && $fees->isNotEmpty();
    $periodLabels = ['once' => 'Разово', 'daily' => 'Ежедневно', 'monthly' => 'Ежемесячно', 'quarterly' => 'Ежеквартально', 'term' => 'За семестр', 'yearly' => 'За год', 'package' => 'Пакет'];

    // Minimum safe availability gating, backed by FinanceConfigurationReadinessService
    // (Phase 3) — the controller computes readiness once, from the same
    // FeePrice::sellable() rows InvoiceCalculationService resolves from, and
    // hands it to this view as data. Only transport/food/uniform are
    // actively gated here; tuition/registration/other stay selectable
    // regardless of readiness (their pricing fallback and dimensional
    // matching are more forgiving by design — see the Phase 3 report).
    $gatedCategories = ['transport', 'food', 'uniform'];
    $serviceIsAvailable = fn ($fee) => ! in_array($fee->category, $gatedCategories, true)
        || ($serviceReadiness[$fee->id]['ready'] ?? false);
    $unavailableReason = fn ($fee) => $serviceReadiness[$fee->id]['reason'] ?? null;
@endphp
<div class="container-fluid py-4">
    <ul class="nav nav-pills mb-4" role="tablist">
        <li class="nav-item"><button type="button" class="nav-link active" data-mode-tab="new" aria-selected="true">Новый ученик</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-mode-tab="existing" aria-selected="false">Существующий ученик</button></li>
    </ul>

    <section class="card shadow-sm mb-4 d-none" id="existing-student-panel">
        <div class="card-header fw-bold">Найти существующего ученика</div>
        <div class="card-body">
            <p class="text-muted">Откройте карточку ученика — учебный год, ступень, класс, форма обучения, активные подписки и неоплаченные счета загрузятся автоматически. Оттуда можно принять оплату по существующему счёту, продлить транспорт/питание или начислить новую услугу без повторного ввода данных.</p>
            <form method="GET" action="{{ route('dashboard.finance.workspace') }}" class="row g-2">
                <div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="Имя, телефон или ID ученика"></div>
                <div class="col-md-4"><button class="btn btn-primary w-100">Найти ученика</button></div>
            </form>
        </div>
    </section>

    <div id="new-student-panel">
    <h2 class="mb-1">Быстрая регистрация нового ученика</h2>
    <p class="text-muted">Минимальное оформление и первоначальный счёт. Валюта расчётов: EGP.</p>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Проверьте введённые данные:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if($academicYears->isEmpty())<div class="alert alert-warning" data-configuration-warning="academic-year">Нет активного учебного года.</div>@endif
    @if($modes->isEmpty())<div class="alert alert-warning" data-configuration-warning="enrollment-mode">Формы обучения не настроены. @can('manage academic years')<a href="{{ route('dashboard.academic.enrollment-modes.index') }}" class="alert-link">Настроить формы обучения</a>@endcan</div>@endif
    @if($fees->isEmpty())<div class="alert alert-warning" data-configuration-warning="services">Финансовые услуги не настроены. Обратитесь к администратору.</div>@endif

    <form method="POST" action="{{ route('dashboard.quick-registration.store') }}" id="quick-registration-form">
        @csrf
        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">1. Минимальные данные ученика</div>
            <div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">Фамилия *</label><input name="student_last_name_ru" value="{{ old('student_last_name_ru') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Имя *</label><input name="student_first_name_ru" value="{{ old('student_first_name_ru') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Отчество</label><input name="student_patronymic_ru" value="{{ old('student_patronymic_ru') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Телефон *</label><input name="phone" value="{{ old('phone') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Дата регистрации *</label><input type="date" name="registration_date" value="{{ old('registration_date', now()->toDateString()) }}" class="form-control" required><div class="form-text">По этой дате система выбирает действующий тариф.</div></div>
                <div class="col-md-4"><label class="form-label">Учебный год *</label><select name="academic_year_id" class="form-select" required><option value="">Выберите учебный год</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string) old('academic_year_id', $defaultAcademicYearId) === (string) $year->id)>{{ $year->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Ступень *</label><select name="stage_id" id="stage" class="form-select" required><option value="">Выберите ступень</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" @selected((string) old('stage_id') === (string) $stage->id)>{{ $stage->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Класс *</label><select name="grade_id" id="grade" class="form-select" required><option value="">Сначала выберите ступень.</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)<option value="{{ $grade->id }}" data-stage="{{ $stage->id }}" @selected((string) old('grade_id') === (string) $grade->id)>{{ $grade->name }}</option>@endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Буква класса *</label><select name="class_id" id="school-class" class="form-select" required><option value="">Сначала выберите класс.</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)@foreach($grade->classes as $class)<option value="{{ $class->id }}" data-grade="{{ $grade->id }}" @selected((string) old('class_id') === (string) $class->id)>{{ $class->name_ru ?: $class->code }}</option>@endforeach @endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Форма обучения *</label><select name="enrollment_mode_id" id="enrollment-mode" class="form-select" required><option value="">Выберите форму обучения</option>@foreach($modes as $mode)<option value="{{ $mode->id }}" @selected((string) old('enrollment_mode_id', $defaultEnrollmentModeId) === (string) $mode->id)>{{ $mode->name_ru }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">2. Финансовые услуги</div>
            <div class="card-body">
                @foreach($groups as $groupKey => $group)
                    <h5 class="mt-3 mb-3">{{ $group['title'] }}</h5>
                    @forelse($group['fees'] as $fee)
                        @php
                            $index = $fees->search(fn ($candidate) => $candidate->id === $fee->id);
                            $oldService = $oldServices->get((string) $fee->id, []);
                            $available = $serviceIsAvailable($fee);
                        @endphp
                        <div class="service-row border rounded p-3 mb-3 {{ $available ? '' : 'opacity-75' }}" data-service-row data-fee-id="{{ $fee->id }}" data-name="{{ $fee->name_ru }}">
                            <div class="form-check">
                                <input class="form-check-input service-toggle" type="checkbox" name="services[{{ $index }}][fee_id]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}" @checked($oldService !== []) @disabled(!$available)>
                                <label class="form-check-label fw-bold" for="fee-{{ $fee->id }}">{{ $fee->name_ru }} — {{ $fee->prices->isNotEmpty() ? 'цена определяется по выбранным параметрам' : number_format($fee->current_amount, 2, '.', '').' EGP' }}</label>
                            </div>
                            @unless($available)
                                <div class="text-danger small mt-1">{{ $unavailableReason($fee) }}</div>
                            @endunless
                            <div class="service-fields row g-3 mt-1 d-none">
                                @if($groupKey !== 'uniform')<input type="hidden" name="services[{{ $index }}][quantity]" value="1" class="quantity">@endif
                                @if($groupKey === 'tuition')
                                    <div class="col-md-3"><label class="form-label">Группа классов</label><select name="services[{{ $index }}][grade_group]" class="form-select price-option"><option value="">По выбранному классу</option>@foreach($fee->prices->pluck('grade_group')->filter()->unique() as $option)<option value="{{ $option }}" @selected(($oldService['grade_group'] ?? null) === $option)>{{ $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Период оплаты</label><select name="services[{{ $index }}][payment_period]" class="form-select price-option"><option value="">Стандартный</option>@foreach($fee->prices->pluck('payment_period')->filter()->unique() as $option)<option value="{{ $option }}" @selected(($oldService['payment_period'] ?? null) === $option)>{{ $periodLabels[$option] ?? $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3 form-check mt-5"><input type="checkbox" value="1" name="services[{{ $index }}][first_last_month]" class="form-check-input price-option" id="first-last-{{ $fee->id }}" @checked(!empty($oldService['first_last_month']))><label for="first-last-{{ $fee->id }}">Первый и последний месяц</label></div>
                                @elseif($groupKey === 'transport')
                                    @php
                                        // Derived live from this fee's actual sellable FeePrice rows — never
                                        // hardcoded — so the period dropdown can only ever offer combinations
                                        // that InvoiceCalculationService would actually resolve for that zone.
                                        $transportPeriodsByZone = $fee->prices->where('option_type', 'zone')
                                            ->groupBy('option_value')
                                            ->map(fn ($prices) => $prices->pluck('payment_period')->filter()->unique()->values());
                                        $transportPeriodRequired = $transportPeriodsByZone->flatten()->isNotEmpty();
                                    @endphp
                                    <div class="col-md-3"><label class="form-label">Район / зона *</label><select name="services[{{ $index }}][transport_area]" class="form-select price-option transport-zone" data-periods-by-zone="{{ $transportPeriodsByZone->toJson() }}"><option value="">Выберите зону</option>@foreach($fee->prices->where('option_type', 'zone')->pluck('option_value')->filter()->unique() as $zone)<option value="{{ $zone }}" @selected(($oldService['transport_area'] ?? null) === $zone)>{{ $zone }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Маршрут *</label><select name="services[{{ $index }}][transport_route_id]" class="form-select"><option value="">Выберите маршрут</option>@foreach($transportRoutes as $route)<option value="{{ $route->id }}" @selected((string) ($oldService['transport_route_id'] ?? '') === (string) $route->id)>{{ $route->name }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Период оплаты{{ $transportPeriodRequired ? ' *' : '' }}</label><select name="services[{{ $index }}][payment_period]" class="form-select price-option transport-period" data-old-value="{{ $oldService['payment_period'] ?? '' }}"><option value="">Выберите зону</option></select></div>
                                    <div class="col-md-3"><label class="form-label">Остановка</label><input name="services[{{ $index }}][transport_stop]" value="{{ $oldService['transport_stop'] ?? '' }}" class="form-control"></div>
                                @elseif($groupKey === 'food')
                                    <div class="col-md-4"><label class="form-label">План питания *</label><select name="services[{{ $index }}][meal_plan_id]" class="form-select price-option"><option value="">Выберите план питания</option>@foreach($mealPlans as $plan)<option value="{{ $plan->id }}" @selected((string) ($oldService['meal_plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name_ru }}</option>@endforeach</select></div>
                                @elseif($groupKey === 'uniform')
                                    <div class="col-md-4"><label class="form-label">Изделие и размер *</label><select name="services[{{ $index }}][uniform_product_id]" class="form-select price-option uniform-product"><option value="">Выберите изделие</option>@foreach($uniformProducts as $product)<option value="{{ $product->id }}" data-item="{{ $product->name_ru }}" data-size="{{ $product->size }}" @selected((string) ($oldService['uniform_product_id'] ?? '') === (string) $product->id)>{{ $product->name_ru }} — {{ $product->size }}</option>@endforeach</select></div>
                                    <div class="col-md-2"><label class="form-label">Количество *</label><input type="number" min="1" max="100" name="services[{{ $index }}][quantity]" value="{{ $oldService['quantity'] ?? 1 }}" class="form-control quantity"></div>
                                @endif
                                <div class="col-md-2"><label class="form-label">Цена</label><div class="resolved-unit fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Стоимость</label><div class="resolved-total fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Оплачено</label><input type="number" min="0" step="0.01" name="services[{{ $index }}][paid_now]" value="{{ $oldService['paid_now'] ?? '0.00' }}" class="form-control paid-now"><div class="invalid-feedback payment-overflow">Оплаченная сумма не может превышать стоимость услуги.</div></div>
                                <div class="col-md-2"><label class="form-label">Остаток</label><div class="remaining fw-semibold">0.00 EGP</div></div>
                                <div class="col-12 tariff-period small text-muted"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">Активные услуги в этой категории не настроены.</p>
                    @endforelse
                @endforeach
            </div>
        </section>

        <section class="card shadow-sm mb-4"><div class="card-header fw-bold">3. Финансовый итог</div><div class="card-body">
            <div class="table-responsive"><table class="table" id="live-summary"><thead><tr><th>Услуга</th><th>Стоимость</th><th>Оплачено</th><th>Остаток</th></tr></thead><tbody><tr class="empty-summary"><td colspan="4" class="text-muted">Услуги не выбраны.</td></tr></tbody><tfoot><tr><th>Итого</th><th id="summary-total">0.00 EGP</th><th id="summary-paid">0.00 EGP</th><th id="summary-remaining">0.00 EGP</th></tr></tfoot></table></div>
            <div><strong>Общая стоимость:</strong> <span id="grand-total">0.00 EGP</span> · <strong>Всего оплачено:</strong> <span id="grand-paid">0.00 EGP</span> · <strong>Общий остаток:</strong> <span id="grand-remaining">0.00 EGP</span></div>
        </div></section>

        @include('dashboard.quick-registration.payment-plan-fields')
        <section class="card shadow-sm mb-4"><div class="card-header fw-bold">5. Оплата</div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" id="payment-method" class="form-select"><option value="">Без оплаты</option><option value="cash" @selected(old('payment_method') === 'cash')>Наличные</option><option value="card" @selected(old('payment_method') === 'card')>Банковская карта</option><option value="bank" @selected(old('payment_method') === 'bank')>Банковский перевод</option><option value="instapay" @selected(old('payment_method') === 'instapay')>InstaPay</option></select></div>
            <div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" id="cash-account" class="form-select"><option value="">Без оплаты</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" data-role="{{ $account->role }}" @selected((string) old('cash_account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select><div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div></div>
            <div class="col-md-4"><label class="form-label">Примечание к оплате</label><input name="payment_note" value="{{ old('payment_note') }}" class="form-control"></div>
        </div></section>

        <div id="service-selection-error" class="text-danger mb-2 d-none">Выберите хотя бы одну финансовую услугу.</div>
        <div id="submit-blocked-error" class="text-danger mb-2 d-none"></div>
        <button class="btn btn-primary btn-lg" @disabled(!$configurationReady)>Создать ученика, счёт и подтвердить оплату</button>
    </form>
    </div>
</div>

<script>
const cents = value => Math.round((Number(value || 0) + Number.EPSILON) * 100);
const money = value => `${(cents(value) / 100).toFixed(2)} EGP`;
const periodLabels = @json($periodLabels);
const stage = document.getElementById('stage');
const grade = document.getElementById('grade');
const schoolClass = document.getElementById('school-class');
const academicYear = document.querySelector('[name="academic_year_id"]');
const enrollmentMode = document.getElementById('enrollment-mode');
const registrationDate = document.querySelector('[name="registration_date"]');
const filterAcademics = () => {
    grade.querySelectorAll('option[data-stage]').forEach(option => option.hidden = option.dataset.stage !== stage.value);
    if (grade.selectedOptions[0]?.hidden) grade.value = '';
    schoolClass.querySelectorAll('option[data-grade]').forEach(option => option.hidden = option.dataset.grade !== grade.value);
    if (schoolClass.selectedOptions[0]?.hidden) schoolClass.value = '';
    grade.options[0].textContent = stage.value ? 'Выберите класс' : 'Сначала выберите ступень.';
    const availableGrades = [...grade.querySelectorAll('option[data-stage]')].some(option => !option.hidden);
    if (stage.value && !availableGrades) grade.options[0].textContent = 'Классы не настроены.';
    schoolClass.options[0].textContent = grade.value ? 'Выберите букву класса' : 'Сначала выберите класс.';
    const availableClasses = [...schoolClass.querySelectorAll('option[data-grade]')].some(option => !option.hidden);
    if (grade.value && !availableClasses) schoolClass.options[0].textContent = 'Классы не настроены.';
};
stage.addEventListener('change', filterAcademics); grade.addEventListener('change', filterAcademics); filterAcademics();

const rows = [...document.querySelectorAll('.service-row')];

// Bug 2: Transport's payment-period options are derived live from this
// fee's own sellable FeePrice rows (data-periods-by-zone, rendered
// server-side) — never hardcoded — and re-filtered to just the selected
// zone every time. Called unconditionally at the top of updateRow() so the
// dropdown is always correct before pricing is requested, regardless of
// which field just changed.
function syncTransportPeriods(row) {
    const zoneSelect = row.querySelector('.transport-zone');
    const periodSelect = row.querySelector('.transport-period');
    if (!zoneSelect || !periodSelect) return;
    const periodsByZone = JSON.parse(zoneSelect.dataset.periodsByZone || '{}');
    const periods = periodsByZone[zoneSelect.value] || [];
    // Preserve an old()-repopulated value (after a validation error) on the
    // very first sync only; afterwards the select's own live value wins.
    const desired = periodSelect.value || periodSelect.dataset.oldValue || '';
    periodSelect.dataset.oldValue = '';
    periodSelect.innerHTML = (zoneSelect.value ? '<option value="">Выберите период оплаты</option>' : '<option value="">Выберите зону</option>')
        + periods.map(p => `<option value="${p}">${periodLabels[p] || p}</option>`).join('');
    if (periods.includes(desired)) periodSelect.value = desired;
}

async function updateRow(row) {
    syncTransportPeriods(row);
    const selected = row.querySelector('.service-toggle').checked;
    const fields = row.querySelector('.service-fields');
    fields.classList.toggle('d-none', !selected);
    fields.querySelectorAll('input, select').forEach(field => field.disabled = !selected);
    if (!selected) { row.dataset.total = row.dataset.paid = row.dataset.remaining = '0'; updateSummary(); return; }

    const body = new FormData();
    body.append('_token', document.querySelector('input[name="_token"]').value);
    body.append('fee_id', row.dataset.feeId);
    body.append('quantity', row.querySelector('.quantity')?.value || 1);
    body.append('academic_year_id', document.querySelector('[name="academic_year_id"]').value);
    body.append('enrollment_mode_id', enrollmentMode.value);
    if (registrationDate.value) body.append('registration_date', registrationDate.value);
    if (grade.value) body.append('grade_id', grade.value);
    ['grade_group', 'payment_period', 'transport_area'].forEach(name => { const input = row.querySelector(`[name$="[${name}]"]`); if (input?.value) body.append(name, input.value); });
    const firstLast = row.querySelector('[name$="[first_last_month]"]'); if (firstLast?.checked) body.append('first_last_month', '1');
    const mealPlan = row.querySelector('[name$="[meal_plan_id]"]'); if (mealPlan?.value) body.append('meal_plan_id', mealPlan.value);
    const product = row.querySelector('.uniform-product')?.selectedOptions[0]; if (product?.value) { body.append('item', product.dataset.item); body.append('size', product.dataset.size); }

    let unit = null, total = null, errorMessage = 'Тариф не настроен.';
    const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {'Accept': 'application/json'}});
    let tariffPeriod = '';
    if (response.ok) {
        const result = await response.json(); unit = Number(result.unit_price); total = Number(result.amount);
        const displayDate = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : null;
        if (result.valid_from) tariffPeriod = `Действует с ${displayDate(result.valid_from)}${result.valid_to ? ` по ${displayDate(result.valid_to)}` : ''}`;
    } else if (response.status === 422) {
        // Surface InvoiceCalculationService's own validation message
        // (e.g. "Для услуги «Транспорт» выберите все параметры тарифа.")
        // instead of a generic string, so the reason is actionable. Never
        // display anything from a non-422 response — that could be a
        // framework/server error message, not a validation reason.
        try {
            const problem = await response.json();
            const firstError = Object.values(problem.errors || {})[0]?.[0];
            if (firstError) errorMessage = firstError;
        } catch (e) { /* keep the generic fallback */ }
    } else {
        errorMessage = 'Не удалось рассчитать тариф. Попробуйте ещё раз.';
    }
    if (unit === null || total === null) {
        row.querySelector('.resolved-unit').textContent = errorMessage;
        row.querySelector('.resolved-total').textContent = '—';
        row.querySelector('.remaining').textContent = '—';
        row.querySelector('.tariff-period').textContent = errorMessage;
        row.dataset.pricingAvailable = 'false';
        row.dataset.total = row.dataset.paid = row.dataset.remaining = '0';
        updateSummary();
        return;
    }
    row.dataset.pricingAvailable = 'true';
    row.querySelector('.tariff-period').textContent = tariffPeriod;
    const paidInput = row.querySelector('.paid-now');
    const paid = Number(paidInput?.value || 0);
    const overpaid = cents(paid) > cents(total);
    paidInput?.classList.toggle('is-invalid', overpaid);
    const remaining = Math.max((cents(total) - cents(paid)) / 100, 0);
    row.dataset.total = (cents(total) / 100).toFixed(2); row.dataset.paid = (cents(paid) / 100).toFixed(2); row.dataset.remaining = remaining.toFixed(2);
    row.querySelector('.resolved-unit').textContent = money(unit); row.querySelector('.resolved-total').textContent = money(total); row.querySelector('.remaining').textContent = money(remaining);
    updateSummary();
}
function updateSummary() {
    const tbody = document.querySelector('#live-summary tbody'); tbody.innerHTML = '';
    let total = 0, paid = 0, remaining = 0;
    rows.filter(row => row.querySelector('.service-toggle').checked).forEach(row => {
        total += cents(row.dataset.total); paid += cents(row.dataset.paid); remaining += cents(row.dataset.remaining);
        const summaryRow = document.createElement('tr');
        [row.dataset.name, money(row.dataset.total), money(row.dataset.paid), money(row.dataset.remaining)].forEach(value => {
            const cell = document.createElement('td'); cell.textContent = value; summaryRow.appendChild(cell);
        });
        tbody.appendChild(summaryRow);
    });
    if (!tbody.children.length) tbody.innerHTML = '<tr><td colspan="4" class="text-muted">Услуги не выбраны.</td></tr>';
    [['summary-total', total], ['summary-paid', paid], ['summary-remaining', remaining], ['grand-total', total], ['grand-paid', paid], ['grand-remaining', remaining]].forEach(([id, value]) => document.getElementById(id).textContent = money(value / 100));
}
rows.forEach(row => { row.querySelectorAll('input, select').forEach(input => { input.addEventListener('change', () => updateRow(row)); input.addEventListener('input', () => updateRow(row)); }); });
// Bug 1: some browsers (confirmed: Safari) restore a checkbox's checked
// state — e.g. from bfcache navigation, or native form-autofill — AFTER
// this script's top-level code has already run, and never dispatch a
// 'change' event when doing so. Reading `.checked` synchronously at parse
// time can therefore see a not-yet-restored, unchecked snapshot even for a
// row the browser is about to show as checked, leaving its price stuck on
// the static "0.00 EGP" placeholder until the user manually toggles it.
// 'pageshow' fires after that restoration completes on every navigation —
// including a plain first load — so re-reading `.checked` there is the
// robust point to resolve pricing for whatever is actually checked.
window.addEventListener('pageshow', () => rows.forEach(updateRow));
grade.addEventListener('change', () => rows.filter(row => row.querySelector('.service-toggle').checked).forEach(updateRow));
[academicYear, schoolClass, enrollmentMode, registrationDate].forEach(input => input.addEventListener('change', () => rows.filter(row => row.querySelector('.service-toggle').checked).forEach(updateRow)));
const paymentMethod = document.getElementById('payment-method');
const cashAccount = document.getElementById('cash-account');
const cashAccountHint = document.getElementById('cash-account-auto-hint');
const methodToRole = {cash: 'operating', bank: 'bank', instapay: 'instapay'};
// The server never trusts cash_account_id for cash/bank/instapay — it always
// resolves the canonical account by role (CashAccount::resolvePaymentAccountId).
// This select must show that same resolved account instead of leaving its
// stale "Без оплаты" placeholder visible while disabled, which previously
// made a real cash payment look like no cash account would be charged.
function syncCashAccountField() {
    const role = methodToRole[paymentMethod.value];
    cashAccount.disabled = !!role;
    if (!role) {
        cashAccountHint.classList.add('d-none');
        return;
    }
    const matchingOption = [...cashAccount.options].find(option => option.dataset.role === role);
    cashAccountHint.classList.remove('d-none');
    if (matchingOption) {
        cashAccount.value = matchingOption.value;
        cashAccountHint.textContent = `Касса определяется автоматически: ${matchingOption.textContent}`;
        cashAccountHint.classList.remove('text-danger');
    } else {
        cashAccount.value = '';
        cashAccountHint.textContent = 'Для этого способа оплаты касса не настроена — обратитесь к администратору.';
        cashAccountHint.classList.add('text-danger');
    }
}
paymentMethod.addEventListener('change', syncCashAccountField);
syncCashAccountField();

const modeTabs = [...document.querySelectorAll('[data-mode-tab]')];
const newPanel = document.getElementById('new-student-panel');
const existingPanel = document.getElementById('existing-student-panel');
modeTabs.forEach(tab => tab.addEventListener('click', () => {
    const existing = tab.dataset.modeTab === 'existing';
    modeTabs.forEach(other => { other.classList.toggle('active', other === tab); other.setAttribute('aria-selected', other === tab ? 'true' : 'false'); });
    newPanel.classList.toggle('d-none', existing);
    existingPanel.classList.toggle('d-none', !existing);
}));

// Bug 3: blocking submission with only event.preventDefault() — as this
// used to do for the "unresolved price" and "overpaid" cases — produces
// exactly the silent "nothing happens" click the employee saw: no request,
// no navigation, no visible message. Every blocked-submit path below must
// now show a specific Russian message and bring the offending row into view.
const submitBlockedError = document.getElementById('submit-blocked-error');
document.getElementById('quick-registration-form').addEventListener('submit', event => {
    const noneSelected = !rows.some(row => row.querySelector('.service-toggle').checked);
    document.getElementById('service-selection-error').classList.toggle('d-none', !noneSelected);

    rows.forEach(row => row.classList.remove('border-danger'));
    const overpaidRow = rows.find(row => row.querySelector('.service-toggle').checked && row.querySelector('.paid-now')?.classList.contains('is-invalid'));
    const unavailableRow = rows.find(row => row.querySelector('.service-toggle').checked && row.dataset.pricingAvailable !== 'true');
    const blockedRow = overpaidRow || unavailableRow;

    // amount > 0 must never post with the payment account still unresolved —
    // mirrors the "Касса = Без оплаты" audit finding: block here instead of
    // letting InvoicePaymentService reject it after the invoice already exists.
    const totalPaidNow = rows.filter(row => row.querySelector('.service-toggle').checked)
        .reduce((sum, row) => sum + cents(row.dataset.paid), 0);
    const unresolvedCashAccount = totalPaidNow > 0 && cashAccount.disabled && cashAccountHint.classList.contains('text-danger');

    if (!noneSelected && !blockedRow && !unresolvedCashAccount) {
        submitBlockedError.classList.add('d-none');
        return;
    }

    event.preventDefault();
    if (blockedRow) {
        blockedRow.classList.add('border-danger');
        submitBlockedError.textContent = overpaidRow
            ? 'Оплаченная сумма превышает стоимость услуги — исправьте выделенную строку ниже.'
            : 'Для одной из выбранных услуг не удалось рассчитать стоимость — заполните все обязательные поля в выделенной строке ниже.';
        submitBlockedError.classList.remove('d-none');
        blockedRow.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (unresolvedCashAccount) {
        submitBlockedError.textContent = 'Для выбранного способа оплаты не настроена касса — оплату принять нельзя. Обратитесь к администратору.';
        submitBlockedError.classList.remove('d-none');
        cashAccountHint.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (!noneSelected) {
        submitBlockedError.classList.add('d-none');
    }
});
</script>
@endsection
