@extends('layouts.dashboard')

@section('content')

@php
    $studyFees = $fees->whereIn('category', [
        'tuition',
        'tuition_regular',
        'tuition_family',
        'tuition_external',
    ]);

    $registrationFee = $fees->firstWhere('category', 'registration');
    $foodFee = $fees->firstWhere('category', 'food');
    $transportFee = $fees->firstWhere('category', 'transport');
    $uniformFee = $fees->firstWhere('category', 'uniform');
    $extraFees = $fees->where('category', 'extra_classes');

    $priceRows = [];

    foreach ($fees as $fee) {
        foreach ($fee->prices as $price) {
            $priceRows[] = [
                'fee_id' => $fee->id,
                'amount' => (float) $price->amount,
                'grade_group' => $price->grade_group ?? null,
                'payment_period' => $price->payment_period ?? null,
                'size' => $price->size ?? null,
                'item' => $price->item ?? null,
                'option_type' => $price->option_type ?? null,
                'option_value' => $price->option_value ?? null,
            ];
        }
    }
@endphp

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">🧾 {{ __('invoices.create') }}</h3>
            <small class="text-muted">{{ __('invoices.title') }}</small>
        </div>

        <a href="{{ route('dashboard.invoices.index') }}" class="btn btn-outline-secondary">
            ← {{ __('invoices.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.invoices.store') }}" id="invoice-form">
        @csrf

        <div class="row g-4">
            <div class="col-lg-8">

                <div class="card modern-card mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">👨‍🎓 {{ __('invoices.student') }}</h5>

                        <label class="form-label">{{ __('invoices.select_student') }}</label>
                        <select name="student_id" class="form-select form-select-lg mb-3">
                            <option value="">{{ __('invoices.select_student') }}</option>
                            @foreach($students as $student)
                                <option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>
                                    {{ $student->name }}
                                    @if($student->grade)
                                        — {{ $student->grade->name_ru ?? $student->grade->name ?? '' }}
                                    @endif
                                </option>
                            @endforeach
                        </select>

                        <hr>

                        <h6 class="fw-bold mb-3">➕ Новый ученик</h6>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <input type="text"
                                       name="new_student[name]"
                                       class="form-control"
                                       placeholder="Имя ученика"
                                       value="{{ old('new_student.name') }}">
                            </div>

                            <div class="col-md-4">
                                <input type="text"
                                       name="new_student[phone]"
                                       class="form-control"
                                       placeholder="Телефон"
                                       value="{{ old('new_student.phone') }}">
                            </div>

                            <div class="col-md-4">
                                <input type="text"
                                       name="new_student[academic_year]"
                                       class="form-control"
                                       placeholder="2025-2026"
                                       value="{{ old('new_student.academic_year', '2025-2026') }}">
                            </div>

                            <div class="col-md-4">
                                <select name="new_student[grade_id]" class="form-select">
                                    <option value="">{{ __('invoices.select_grade') ?? 'Выберите класс' }}</option>
                                    @foreach($grades as $grade)
                                        <option value="{{ $grade->id }}" @selected(old('new_student.grade_id') == $grade->id)>
                                            {{ $grade->name_ru ?? $grade->name ?? 'Grade #' . $grade->id }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-8">
                                <input type="text"
                                       name="invoice_note"
                                       class="form-control"
                                       placeholder="Примечание"
                                       value="{{ old('invoice_note') }}">
                            </div>
                        </div>
                    </div>
                </div>

                @if($studyFees->count())
                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">🎓 {{ __('invoices.study_system') ?? 'Система обучения' }}</h5>

                            <div class="row g-3">
                                @foreach($studyFees as $fee)
                                    <div class="col-md-4">
                                        <label class="choice-card study-choice">
                                            <input type="radio"
                                                   class="study-radio"
                                                   name="study_fee"
                                                   value="{{ $fee->id }}"
                                                   data-fee-id="{{ $fee->id }}">

                                            <input type="checkbox"
                                                   class="d-none fee-checkbox"
                                                   name="fees[]"
                                                   value="{{ $fee->id }}"
                                                   data-fee-id="{{ $fee->id }}"
                                                   data-base-price="{{ $fee->amount ?? 0 }}">

                                            <div class="choice-icon">📘</div>
                                            <div class="fw-bold">{{ $fee->name_ru }}</div>
                                            <small class="text-muted">Цена по классу и периоду</small>
                                        </label>
                                    </div>
                                @endforeach
                            </div>

                            <div class="row g-3 mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('invoices.grade_group') ?? 'Группа классов' }}</label>
                                    <select id="study-grade-group" class="form-select">
                                        <option value="">Выберите группу</option>
                                        <option value="Подготовительный">Подготовительный</option>
                                        <option value="1-4">1-4</option>
                                        <option value="5-6">5-6</option>
                                        <option value="7-8">7-8</option>
                                        <option value="9-11">9-11</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">{{ __('invoices.payment_period') ?? 'Период оплаты' }}</label>
                                    <select id="study-payment-period" class="form-select">
                                        <option value="">Выберите период</option>
                                        <option value="monthly">Ежемесячно</option>
                                        <option value="quarterly">Каждые 3 месяца</option>
                                        <option value="yearly">Ежегодно</option>
                                    </select>

                                    <div class="form-check mt-2 d-none" id="first-last-month-box">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               id="first-last-month-check"
                                               value="1">
                                        <label class="form-check-label" for="first-last-month-check">
                                            Первый месяц + последний месяц
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($registrationFee)
                    <div class="card modern-card mb-4">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-1">📝 {{ $registrationFee->name_ru }}</h5>
                                <small class="text-muted">{{ __('invoices.registration') ?? 'Регистрационный взнос' }}</small>
                            </div>

                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input fee-checkbox"
                                       type="checkbox"
                                       name="fees[]"
                                       value="{{ $registrationFee->id }}"
                                       data-fee-id="{{ $registrationFee->id }}"
                                       data-base-price="{{ $registrationFee->amount ?? 0 }}">
                            </div>
                        </div>
                    </div>
                @endif

                @if($foodFee)
                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">🍽 {{ __('invoices.food') ?? 'Питание' }}</h5>

                            <input type="checkbox"
                                   class="d-none fee-checkbox"
                                   id="food-checkbox"
                                   name="fees[]"
                                   value="{{ $foodFee->id }}"
                                   data-fee-id="{{ $foodFee->id }}"
                                   data-base-price="{{ $foodFee->amount ?? 0 }}">

                            <input type="hidden" name="option_type[{{ $foodFee->id }}]" value="food_type">

                            <select name="option_value[{{ $foodFee->id }}]"
                                    id="food-option"
                                    class="form-select">
                                <option value="">{{ __('invoices.no_food') ?? 'Без питания' }}</option>
                                <option value="daily">Ежедневно</option>
                                <option value="weekly">Еженедельно</option>
                                <option value="monthly">Ежемесячно</option>
                                <option value="full_day">Полный день</option>
                                <option value="half_day">Половина дня</option>
                            </select>
                        </div>
                    </div>
                @endif

                @if($transportFee)
                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">🚌 {{ __('invoices.transport') ?? 'Трансфер' }}</h5>

                            <input type="checkbox"
                                   class="d-none fee-checkbox"
                                   id="transport-checkbox"
                                   name="fees[]"
                                   value="{{ $transportFee->id }}"
                                   data-fee-id="{{ $transportFee->id }}"
                                   data-base-price="{{ $transportFee->amount ?? 0 }}">

                            <input type="hidden" name="option_type[{{ $transportFee->id }}]" value="zone">

                            <select name="option_value[{{ $transportFee->id }}]"
                                    id="transport-zone"
                                    class="form-select">
                                <option value="">{{ __('invoices.no_transport') ?? 'Без трансфера' }}</option>

                                @foreach($transportFee->prices->where('option_type', 'zone')->pluck('option_value')->unique()->filter() as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                @if($uniformFee)
                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">👕 {{ __('invoices.uniform') ?? 'Школьная форма' }}</h5>

                            <input type="checkbox"
                                   class="d-none fee-checkbox"
                                   id="uniform-checkbox"
                                   name="fees[]"
                                   value="{{ $uniformFee->id }}"
                                   data-fee-id="{{ $uniformFee->id }}"
                                   data-base-price="{{ $uniformFee->amount ?? 0 }}">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('invoices.item') ?? 'Тип формы' }}</label>
                                    <select name="uniform_item[{{ $uniformFee->id }}]"
                                            id="uniform-item"
                                            class="form-select">
                                        <option value="">Не выбрано</option>
                                        <option value="full_set">Комплект формы</option>
                                        <option value="tshirt">Футболка</option>
                                        <option value="polo">Поло</option>
                                        <option value="pants">Брюки</option>
                                        <option value="jacket">Куртка</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">{{ __('invoices.size') ?? 'Размер' }}</label>
                                    <select name="uniform_size[{{ $uniformFee->id }}]"
                                            id="uniform-size"
                                            class="form-select">
                                        <option value="">Не выбрано</option>
                                        @@@foreach(['8','10','12','14','16','XS','S','M','L','XL','XXL'] as $size)
                                            <option value="{{ $size }}">{{ $size }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($extraFees->count())
                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">📚 {{ __('invoices.extras') ?? 'Дополнительные занятия' }}</h5>

                            <div class="row g-3">
                                @foreach($extraFees as $fee)
                                    <div class="col-md-6">
                                        <label class="choice-card">
                                            <input type="checkbox"
                                                   class="fee-checkbox"
                                                   name="fees[]"
                                                   value="{{ $fee->id }}"
                                                   data-fee-id="{{ $fee->id }}"
                                                   data-base-price="{{ $fee->amount ?? 0 }}">

                                            <input type="hidden" name="option_type[{{ $fee->id }}]" value="hours">
                                            <input type="hidden" name="option_value[{{ $fee->id }}]" value="8">

                                            <div class="fw-bold">{{ $fee->name_ru }}</div>
                                            <small class="text-muted">Пакет 8 часов</small>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

            </div>

            <div class="col-lg-4">
                <div class="sticky-summary">

                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">💸 {{ __('invoices.discount') }}</h5>

                            <select name="discount_type" id="discount_type" class="form-select mb-3">
                                <option value="">{{ __('invoices.no_discount') }}</option>
                                <option value="fixed">{{ __('invoices.fixed_discount') }}</option>
                                <option value="percent">{{ __('invoices.percent_discount') }}</option>
                            </select>

                            <input type="number"
                                   name="discount_value"
                                   id="discount_value"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   placeholder="0">
                        </div>
                    </div>

                    <div class="card modern-card mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3">💳 {{ __('invoices.payment') }}</h5>

                            <label class="form-label">{{ __('invoices.payment_method') }}</label>
                            <select name="payment_method" class="form-select mb-3" required>
                                <option value="cash">{{ __('invoices.cash') }}</option>
                                <option value="bank">{{ __('invoices.bank') }}</option>
                                <option value="card">{{ __('invoices.card') }}</option>
                                <option value="transfer">{{ __('invoices.transfer') }}</option>
                            </select>

                            <label class="form-label">{{ __('invoices.cash_account') }}</label>
                            <select name="cash_account_id" class="form-select mb-3" required>
                                <option value="">{{ __('invoices.select_cash_account') }}</option>
                                @foreach($cashAccounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                                @endforeach
                            </select>

                            <label class="form-label">{{ __('invoices.paid_amount') }}</label>
                            <input type="number"
                                   id="paid_amount"
                                   name="paid_amount"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   placeholder="{{ __('invoices.full_payment_if_empty') }}">
                        </div>
                    </div>

                    <div class="card total-card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span>{{ __('invoices.total_amount') }}</span>
                                <strong><span id="total">0.00</span></strong>
                            </div>

                            <div class="d-flex justify-content-between mb-2 text-warning">
                                <span>{{ __('invoices.discount_amount') }}</span>
                                <strong><span id="discount">0.00</span></strong>
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between fs-5 mb-2">
                                <span>{{ __('invoices.net_amount') }}</span>
                                <strong><span id="net">0.00</span></strong>
                            </div>

                            <div class="d-flex justify-content-between text-danger">
                                <span>{{ __('invoices.remaining_amount') }}</span>
                                <strong><span id="remaining">0.00</span></strong>
                            </div>

                            <button class="btn btn-primary btn-lg w-100 mt-4">
                                💾 {{ __('invoices.save') }}
                            </button>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </form>
</div>

<style>
    .modern-card {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(0,0,0,.06);
    }

    .choice-card {
        display: block;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 16px;
        cursor: pointer;
        background: #fff;
        transition: .2s ease;
        height: 100%;
    }

    .choice-card:hover {
        border-color: #0d6efd;
        background: #f8fbff;
        transform: translateY(-2px);
    }

    .choice-card:has(input:checked) {
        border-color: #0d6efd;
        background: #eef5ff;
    }

    .choice-icon {
        font-size: 28px;
        margin-bottom: 8px;
    }

    .sticky-summary {
        position: sticky;
        top: 20px;
    }

    .total-card {
        border: 0;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff, #f5f8ff);
    }
</style>

<script>
    const priceRows = @json($priceRows);

    function getCheckedFees() {
        return Array.from(document.querySelectorAll('.fee-checkbox')).filter(cb => cb.checked);
    }

    function findPrice(feeId, filters = {}) {
        const rows = priceRows.filter(row => Number(row.fee_id) === Number(feeId));

        let matched = rows.find(row => {
            for (const key in filters) {
                if (filters[key] && row[key] !== filters[key]) return false;
            }

            return true;
        });

        if (matched) return Number(matched.amount || 0);

        const checkbox = document.querySelector(`.fee-checkbox[data-fee-id="${feeId}"]`);
        return Number(checkbox?.dataset.basePrice || 0);
    }

    function selectedStudyFeeId() {
        const radio = document.querySelector('.study-radio:checked');
        return radio ? radio.dataset.feeId : null;
    }

    function syncStudy() {
        document.querySelectorAll('.study-choice .fee-checkbox').forEach(cb => cb.checked = false);

        const id = selectedStudyFeeId();

        document.querySelectorAll('input[name^="grade_group["], input[name^="payment_period["], input[name^="first_last_month["]').forEach(el => {
            el.remove();
        });

        if (id) {
            const cb = document.querySelector(`.study-choice .fee-checkbox[data-fee-id="${id}"]`);
            if (cb) cb.checked = true;

            const grade = document.getElementById('study-grade-group')?.value || '';
            const period = document.getElementById('study-payment-period')?.value || '';
            const firstLastChecked = document.getElementById('first-last-month-check')?.checked || false;

            const form = document.getElementById('invoice-form');

            let gradeInput = document.createElement('input');
            gradeInput.type = 'hidden';
            gradeInput.name = `grade_group[${id}]`;
            gradeInput.value = grade;
            form.appendChild(gradeInput);

            let periodInput = document.createElement('input');
            periodInput.type = 'hidden';
            periodInput.name = `payment_period[${id}]`;
            periodInput.value = period;
            form.appendChild(periodInput);

            if (firstLastChecked && period === 'monthly') {
                let firstLastInput = document.createElement('input');
                firstLastInput.type = 'hidden';
                firstLastInput.name = `first_last_month[${id}]`;
                firstLastInput.value = '1';
                form.appendChild(firstLastInput);
            }
        }
    }

    function syncFirstLastMonthVisibility() {
        const period = document.getElementById('study-payment-period')?.value || '';
        const box = document.getElementById('first-last-month-box');
        const check = document.getElementById('first-last-month-check');

        if (!box) return;

        if (period === 'monthly') {
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
            if (check) check.checked = false;
        }
    }

    function syncFood() {
        const food = document.getElementById('food-option');
        const cb = document.getElementById('food-checkbox');

        if (food && cb) cb.checked = !!food.value;
    }

    function syncTransport() {
        const zone = document.getElementById('transport-zone');
        const cb = document.getElementById('transport-checkbox');

        if (zone && cb) cb.checked = !!zone.value;
    }

    function syncUniform() {
        const item = document.getElementById('uniform-item');
        const size = document.getElementById('uniform-size');
        const cb = document.getElementById('uniform-checkbox');

        if (item && size && cb) cb.checked = !!item.value && !!size.value;
    }

    function calculate() {
        syncFirstLastMonthVisibility();
        syncStudy();
        syncFood();
        syncTransport();
        syncUniform();

        let total = 0;

        getCheckedFees().forEach(cb => {
            const feeId = cb.dataset.feeId;
            let filters = {};

            if (selectedStudyFeeId() == feeId) {
                filters.grade_group = document.getElementById('study-grade-group')?.value || '';
                filters.payment_period = document.getElementById('study-payment-period')?.value || '';
            }

            if (document.getElementById('transport-checkbox') === cb) {
                filters.option_type = 'zone';
                filters.option_value = document.getElementById('transport-zone')?.value || '';
            }

            if (document.getElementById('food-checkbox') === cb) {
                filters.option_type = 'food_type';
                filters.option_value = document.getElementById('food-option')?.value || '';
            }

            if (document.getElementById('uniform-checkbox') === cb) {
                filters.item = document.getElementById('uniform-item')?.value || '';
                filters.size = document.getElementById('uniform-size')?.value || '';
            }

            let amount = findPrice(feeId, filters);

            if (selectedStudyFeeId() == feeId) {
                const period = document.getElementById('study-payment-period')?.value || '';
                const firstLastChecked = document.getElementById('first-last-month-check')?.checked || false;

                if (period === 'monthly' && firstLastChecked) {
                    amount = amount * 2;
                }
            }

            total += amount;
        });

        const discountType = document.getElementById('discount_type')?.value || '';
        const discountValue = Number(document.getElementById('discount_value')?.value || 0);

        let discount = 0;

        if (discountType === 'fixed') discount = discountValue;
        if (discountType === 'percent') discount = total * discountValue / 100;

        discount = Math.min(discount, total);

        const net = Math.max(total - discount, 0);

        const paidInput = document.getElementById('paid_amount')?.value;
        let paid = paidInput === '' ? net : Number(paidInput || 0);
        paid = Math.min(paid, net);

        const remaining = Math.max(net - paid, 0);

        document.getElementById('total').innerText = total.toFixed(2);
        document.getElementById('discount').innerText = discount.toFixed(2);
        document.getElementById('net').innerText = net.toFixed(2);
        document.getElementById('remaining').innerText = remaining.toFixed(2);
    }

    document.querySelectorAll('input, select').forEach(el => {
        el.addEventListener('input', calculate);
        el.addEventListener('change', calculate);
    });

    calculate();
</script>

@endsection