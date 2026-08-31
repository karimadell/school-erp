@extends('layouts.dashboard')
@section('content')
@php
    $tuitionCategories = [
        \App\Models\Fee::CATEGORY_TUITION,
        \App\Models\Fee::CATEGORY_TUITION_REGULAR,
        \App\Models\Fee::CATEGORY_TUITION_FAMILY,
        \App\Models\Fee::CATEGORY_TUITION_EXTERNAL,
    ];
    $categoryLabels = [
        \App\Models\Fee::CATEGORY_TUITION => 'Обучение',
        \App\Models\Fee::CATEGORY_TUITION_REGULAR => 'Обучение',
        \App\Models\Fee::CATEGORY_TUITION_FAMILY => 'Обучение',
        \App\Models\Fee::CATEGORY_TUITION_EXTERNAL => 'Обучение',
        \App\Models\Fee::CATEGORY_TRANSPORT => 'Трансфер',
        \App\Models\Fee::CATEGORY_FOOD => 'Питание',
        \App\Models\Fee::CATEGORY_UNIFORM => 'Школьная форма',
        \App\Models\Fee::CATEGORY_REGISTRATION => 'Регистрационный взнос',
    ];
@endphp
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between mb-4">
        <div><h1 class="h3">Новый счёт</h1><div class="text-muted">{{ $student->full_name }}</div></div>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard.students.finance', $student) }}">Назад</a>
    </div>

    @if(!$year)
        <div class="alert alert-warning">У ученика нет активного зачисления.</div>
    @else
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('dashboard.students.invoices.store', $student) }}" id="modern-invoice-form">
            @csrf
            <input type="hidden" name="student_id" value="{{ $student->id }}">
            <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
            <input type="hidden" name="initial_payment_amount" value="0">

            <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
                <div class="col-md-3"><label class="form-label">Учебный год</label><input class="form-control" value="{{ $year->name }}" readonly></div>
                <div class="col-md-3"><label class="form-label">Дата выставления счёта</label><input type="date" name="pricing_date" id="invoice-pricing-date" class="form-control" value="{{ old('pricing_date', now()->toDateString()) }}" required><div class="form-text">По этой дате система выбирает действующий тариф.</div></div>
                <div class="col-md-3"><label class="form-label">Срок оплаты</label><input type="date" name="due_date" class="form-control" value="{{ old('due_date', $year->end_date?->format('Y-m-d')) }}" required></div>
                <div class="col-md-3"><label class="form-label">Примечание</label><input name="notes" class="form-control" value="{{ old('notes') }}"></div>
            </div></div>

            @include('dashboard.finance.invoices.payment-plan-fields')

            <div class="d-flex justify-content-between align-items-end mb-2">
                <div><h2 class="h5 mb-1">Услуги</h2><div class="text-muted small">Выберите одну или несколько услуг. Настройки появятся только для выбранных услуг.</div></div>
                <span class="badge bg-primary" id="selected-service-count">Выбрано: 0</span>
            </div>
            <div class="row g-3">
                @forelse($fees as $fee)
                    @php
                        $isTuition = in_array($fee->category, $tuitionCategories, true);
                        $isContextual = $isTuition || in_array($fee->category, [\App\Models\Fee::CATEGORY_TRANSPORT, \App\Models\Fee::CATEGORY_FOOD, \App\Models\Fee::CATEGORY_UNIFORM], true);
                        $oldSelected = in_array($fee->id, old('fees', []));
                        $isStructuredTransport = $fee->category === \App\Models\Fee::CATEGORY_TRANSPORT
                            && $fee->prices->contains(fn ($price) => $price->option_type === 'zone' && filled($price->option_value) && filled($price->payment_period));
                    @endphp
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm service-card {{ $oldSelected ? 'border-primary' : 'border-0' }}" data-fee="{{ $fee->id }}">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input fee-check" type="checkbox" name="fees[]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}" @checked($oldSelected)>
                                    <label class="form-check-label" for="fee-{{ $fee->id }}"><strong>{{ $fee->name_ru }}</strong><span class="badge bg-light text-dark ms-1">{{ $categoryLabels[$fee->category] ?? 'Услуга' }}</span></label>
                                </div>

                                <div class="service-config mt-3 {{ $oldSelected ? '' : 'd-none' }}">
                                    @if($isStructuredTransport)
                                        <div class="row g-2">
                                            <div class="col-md-7">
                                                <label class="form-label">Зона тарифа</label>
                                                <select class="form-select transport-zone-select">
                                                    @foreach($fee->prices->where('option_type', 'zone')->pluck('option_value')->filter()->unique() as $zone)
                                                        <option value="{{ $zone }}">{{ $zone }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Период оплаты</label>
                                                <select class="form-select transport-period-select">
                                                    @foreach($fee->prices->where('option_type', 'zone')->pluck('payment_period')->filter()->unique() as $period)
                                                        <option value="{{ $period }}">{{ ['monthly' => 'Ежемесячно', 'yearly' => 'За год'][$period] ?? $period }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <select class="tariff-select d-none" aria-hidden="true" tabindex="-1">
                                            @foreach($fee->prices->where('option_type', 'zone') as $price)
                                                <option value="{{ $price->id }}"
                                                    data-grade-group="{{ $price->grade_group }}" data-payment-period="{{ $price->payment_period }}"
                                                    data-size="{{ $price->size }}" data-item="{{ $price->item }}"
                                                    data-option-type="{{ $price->option_type }}" data-option-value="{{ $price->option_value }}"></option>
                                            @endforeach
                                        </select>
                                    @elseif($fee->prices->count() > 1 || ($fee->prices->first() && $isContextual))
                                        <label class="form-label">
                                            {{ $isTuition ? 'Класс и период оплаты' : match($fee->category) {
                                                \App\Models\Fee::CATEGORY_TRANSPORT => 'Зона / вариант трансфера',
                                                \App\Models\Fee::CATEGORY_FOOD => 'План питания',
                                                \App\Models\Fee::CATEGORY_UNIFORM => 'Предмет, размер и вариант',
                                                default => 'Вариант тарифа',
                                            } }}
                                        </label>
                                        <select class="form-select tariff-select" data-fee="{{ $fee->id }}">
                                            @foreach($fee->prices as $price)
                                                <option value="{{ $price->id }}"
                                                    data-grade-group="{{ $price->grade_group }}" data-payment-period="{{ $price->payment_period }}"
                                                    data-size="{{ $price->size }}" data-item="{{ $price->item }}"
                                                    data-option-type="{{ $price->option_type }}" data-option-value="{{ $price->option_value }}">
                                                    {{ collect([$price->grade_group, $price->payment_period, $price->item, $price->size, $price->option_value])->filter()->implode(' · ') ?: 'Основной тариф' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @elseif($fee->prices->count() === 1)
                                        <input type="hidden" class="tariff-select" data-fee="{{ $fee->id }}" value="{{ $fee->prices->first()->id }}"
                                            data-grade-group="{{ $fee->prices->first()->grade_group }}" data-payment-period="{{ $fee->prices->first()->payment_period }}"
                                            data-size="{{ $fee->prices->first()->size }}" data-item="{{ $fee->prices->first()->item }}"
                                            data-option-type="{{ $fee->prices->first()->option_type }}" data-option-value="{{ $fee->prices->first()->option_value }}">
                                    @endif
                                    <div class="mt-2">Цена: <strong class="price-preview" aria-live="polite">Не выбрано</strong></div>
                                    <div class="price-error small text-danger d-none" role="alert"></div>
                                    <div class="tariff-validity small text-muted"></div>
                                </div>

                                <input type="hidden" name="fee_price_id[{{ $fee->id }}]">
                                <input type="hidden" name="grade_group[{{ $fee->id }}]"><input type="hidden" name="payment_period[{{ $fee->id }}]">
                                <input type="hidden" name="uniform_size[{{ $fee->id }}]"><input type="hidden" name="uniform_item[{{ $fee->id }}]">
                                <input type="hidden" name="option_type[{{ $fee->id }}]"><input type="hidden" name="option_value[{{ $fee->id }}]">
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="alert alert-warning">Активные услуги не настроены.</div>
                @endforelse
            </div>

            <div class="card border-0 shadow-sm mt-4"><div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted">Предварительный итог</div><strong id="invoice-preview-total">0.00 EGP</strong><div class="small text-muted">Окончательная сумма рассчитывается сервером.</div></div>
                <button type="submit" class="btn btn-primary">Создать счёт</button>
            </div></div>
        </form>
    @endif
</div>

@if($year)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('modern-invoice-form');
    const date = document.getElementById('invoice-pricing-date');
    const token = form.querySelector('[name="_token"]').value;
    const fields = {gradeGroup: 'grade_group', paymentPeriod: 'payment_period', size: 'uniform_size', item: 'uniform_item', optionType: 'option_type', optionValue: 'option_value'};
    const submit = form.querySelector('button[type="submit"]');
    const money = amount => Number(amount).toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' EGP';
    let previewGeneration = 0;
    let previewController = null;

    const update = async () => {
        const generation = ++previewGeneration;
        previewController?.abort();
        previewController = new AbortController();
        let cents = 0;
        let unavailable = false;
        let selected = 0;
        form.dataset.pricingAvailable = 'false';
        submit.disabled = true;
        for (const card of document.querySelectorAll('.service-card')) {
            const check = card.querySelector('.fee-check');
            const config = card.querySelector('.service-config');
            const preview = card.querySelector('.price-preview');
            const error = card.querySelector('.price-error');
            card.classList.toggle('border-primary', check.checked);
            card.classList.toggle('border-0', !check.checked);
            config.classList.toggle('d-none', !check.checked);
            if (!check.checked) {
                preview.textContent = 'Не выбрано';
                error.classList.add('d-none');
                card.querySelector('.tariff-validity').textContent = '';
                continue;
            }
            selected++;
            preview.textContent = 'Расчёт…';
            error.classList.add('d-none');

            const fee = card.dataset.fee;
            const select = card.querySelector('.tariff-select');
            const zone = card.querySelector('.transport-zone-select');
            const period = card.querySelector('.transport-period-select');
            if (select?.tagName === 'SELECT' && zone && period) {
                const matching = Array.from(select.options).find(candidate => candidate.dataset.optionValue === zone.value && candidate.dataset.paymentPeriod === period.value);
                select.value = matching?.value || '';
            }
            const option = select?.selectedOptions?.[0] || select;
            if (option) {
                card.querySelector(`[name="fee_price_id[${fee}]"]`).value = option.value || '';
                Object.entries(fields).forEach(([key, name]) => card.querySelector(`[name="${name}[${fee}]"]`).value = option.dataset[key] || '');
            }

            const body = new FormData();
            body.append('_token', token); body.append('fee_id', fee); body.append('quantity', '1');
            body.append('academic_year_id', '{{ $year->id }}'); body.append('grade_id', '{{ $student->currentEnrollment?->grade_id }}');
            body.append('enrollment_mode_id', '{{ $student->currentEnrollment?->enrollment_mode_id }}'); body.append('pricing_date', date.value);
            if (option?.value) body.append('fee_price_id', option.value);
            if (option) Object.entries(fields).forEach(([key, name]) => { if (option.dataset[key]) body.append(name === 'uniform_size' ? 'size' : name === 'uniform_item' ? 'item' : name, option.dataset[key]); });
            let response;
            try {
                response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {Accept: 'application/json'}, signal: previewController.signal});
            } catch (requestError) {
                if (requestError.name === 'AbortError' || generation !== previewGeneration) return;
                response = null;
            }
            if (generation !== previewGeneration) return;
            if (!response?.ok) {
                preview.textContent = 'Цена не определена';
                error.textContent = 'Для выбранных параметров тариф не найден.';
                error.classList.remove('d-none');
                unavailable = true;
                continue;
            }
            const result = await response.json();
            if (generation !== previewGeneration) return;
            if (!Number.isFinite(Number(result.amount)) || Number(result.amount) <= 0) {
                preview.textContent = 'Цена не определена';
                error.textContent = 'Сервер не вернул корректную цену тарифа.';
                error.classList.remove('d-none');
                unavailable = true;
                continue;
            }
            cents += Math.round(Number(result.amount) * 100);
            preview.textContent = money(result.amount);
            const format = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '';
            card.querySelector('.tariff-validity').textContent = result.valid_from ? `Действует с ${format(result.valid_from)}${result.valid_to ? ` по ${format(result.valid_to)}` : ''}` : '';
        }
        if (generation !== previewGeneration) return;
        document.getElementById('selected-service-count').textContent = `Выбрано: ${selected}`;
        document.getElementById('invoice-preview-total').textContent = money(cents / 100);
        form.dataset.pricingAvailable = !unavailable && selected > 0 ? 'true' : 'false';
        submit.disabled = form.dataset.pricingAvailable !== 'true';
    };
    document.querySelectorAll('.fee-check,.tariff-select,.transport-zone-select,.transport-period-select').forEach(element => element.addEventListener('change', update));
    date.addEventListener('change', update);
    form.addEventListener('submit', event => { if (!form.querySelector('.fee-check:checked') || form.dataset.pricingAvailable === 'false') event.preventDefault(); });
    update();

    // Finance V2, Phase 2B corrective pass (review finding M2): narrow the
    // "План оплаты" dropdown to only the PaymentPlan(s) explicitly assigned
    // to EVERY currently-checked Fee (the intersection) — a plan valid for
    // Tuition but not Transport must not appear once both are checked,
    // matching InvoiceIssuanceService::issue()'s own server-side rule
    // (Phase 2B / M1: every Fee on the invoice must allow the same
    // strategy). That server-side check remains the authoritative
    // backstop regardless of what this filter shows.
    const planSelect = document.getElementById('modern-invoice-payment-plan-select');
    if (planSelect) {
        const feePlanMap = JSON.parse(planSelect.dataset.feePlanMap || '{}');
        const filterPaymentPlans = () => {
            const checkedFeeIds = Array.from(document.querySelectorAll('.fee-check:checked')).map(el => el.value);
            let allowed = null;
            if (checkedFeeIds.length > 0) {
                allowed = checkedFeeIds.reduce((intersection, feeId) => {
                    const plansForFee = new Set((feePlanMap[feeId] || []).map(String));
                    return intersection === null ? plansForFee : new Set([...intersection].filter(id => plansForFee.has(id)));
                }, null);
            }
            let selectedStillValid = false;
            Array.from(planSelect.options).forEach(option => {
                if (option.value === '') { return; }
                const eligible = allowed !== null && allowed.has(option.value);
                option.hidden = !eligible;
                option.disabled = !eligible;
                if (eligible && option.value === planSelect.value) { selectedStillValid = true; }
            });
            if (planSelect.value !== '' && !selectedStillValid) { planSelect.value = ''; }
        };
        document.querySelectorAll('.fee-check').forEach(element => element.addEventListener('change', filterPaymentPlans));
        filterPaymentPlans();
    }
});
</script>
@endpush
@endif
@endsection
