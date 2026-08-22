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
                    @endphp
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm service-card {{ $oldSelected ? 'border-primary' : 'border-0' }}" data-fee="{{ $fee->id }}">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input fee-check" type="checkbox" name="fees[]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}" @checked($oldSelected)>
                                    <label class="form-check-label" for="fee-{{ $fee->id }}"><strong>{{ $fee->name_ru }}</strong><span class="badge bg-light text-dark ms-1">{{ $categoryLabels[$fee->category] ?? 'Услуга' }}</span></label>
                                </div>

                                <div class="service-config mt-3 {{ $oldSelected ? '' : 'd-none' }}">
                                    @if($fee->prices->count() > 1 || ($fee->prices->first() && $isContextual))
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
                                    <div class="mt-2">Цена: <strong class="price-preview">Определяется сервером</strong></div>
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
                <button class="btn btn-primary">Создать счёт</button>
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

    const update = async () => {
        let cents = 0;
        let unavailable = false;
        let selected = 0;
        for (const card of document.querySelectorAll('.service-card')) {
            const check = card.querySelector('.fee-check');
            const config = card.querySelector('.service-config');
            card.classList.toggle('border-primary', check.checked);
            card.classList.toggle('border-0', !check.checked);
            config.classList.toggle('d-none', !check.checked);
            if (!check.checked) continue;
            selected++;

            const fee = card.dataset.fee;
            const select = card.querySelector('.tariff-select');
            const option = select?.selectedOptions?.[0] || select;
            if (option) {
                card.querySelector(`[name="fee_price_id[${fee}]"]`).value = option.value || '';
                Object.entries(fields).forEach(([key, name]) => card.querySelector(`[name="${name}[${fee}]"]`).value = option.dataset[key] || '');
            }

            const body = new FormData();
            body.append('_token', token); body.append('fee_id', fee); body.append('quantity', '1');
            body.append('academic_year_id', '{{ $year->id }}'); body.append('grade_id', '{{ $student->currentEnrollment?->grade_id }}');
            body.append('enrollment_mode_id', '{{ $student->currentEnrollment?->enrollment_mode_id }}'); body.append('registration_date', date.value);
            if (option) Object.entries(fields).forEach(([key, name]) => { if (option.dataset[key]) body.append(name === 'uniform_size' ? 'size' : name === 'uniform_item' ? 'item' : name, option.dataset[key]); });
            const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {Accept: 'application/json'}});
            if (!response.ok) { card.querySelector('.price-preview').textContent = 'Тариф не настроен'; unavailable = true; continue; }
            const result = await response.json();
            cents += Math.round(Number(result.amount) * 100);
            card.querySelector('.price-preview').textContent = Number(result.amount).toFixed(2) + ' EGP';
            const format = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : '';
            card.querySelector('.tariff-validity').textContent = result.valid_from ? `Действует с ${format(result.valid_from)}${result.valid_to ? ` по ${format(result.valid_to)}` : ''}` : '';
        }
        document.getElementById('selected-service-count').textContent = `Выбрано: ${selected}`;
        document.getElementById('invoice-preview-total').textContent = (cents / 100).toFixed(2) + ' EGP';
        form.dataset.pricingAvailable = unavailable ? 'false' : 'true';
    };
    document.querySelectorAll('.fee-check,.tariff-select').forEach(element => element.addEventListener('change', update));
    date.addEventListener('change', update);
    form.addEventListener('submit', event => { if (!form.querySelector('.fee-check:checked') || form.dataset.pricingAvailable === 'false') event.preventDefault(); });
    update();
});
</script>
@endpush
@endif
@endsection
