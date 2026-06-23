@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">💰 Добавить цену</h3>
            <small class="text-muted">Создание новой цены для услуги с датой начала действия</small>
        </div>

        <a href="{{ route('dashboard.fee-prices.index') }}" class="btn btn-secondary">
            ← Назад
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

    <form method="POST" action="{{ route('dashboard.fee-prices.store') }}">
        @csrf

        {{-- MAIN --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-bold">📌 Основные данные</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Услуга</label>
                        <select name="fee_id" id="fee_id" class="form-select" required>
                            <option value="">-- Выберите услугу --</option>

                            @foreach($fees as $fee)
                                <option value="{{ $fee->id }}"
                                        data-category="{{ $fee->category }}"
                                        @selected(old('fee_id') == $fee->id)>
                                    {{ $fee->name_ru }}
                                    @if($fee->category)
                                        — {{ $fee->category }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Сначала выберите услугу, затем появятся нужные поля.</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Период оплаты</label>
                        <select name="payment_period" class="form-select">
                            <option value="">-- Не указано --</option>
                            <option value="once" @selected(old('payment_period') === 'once')>Один раз</option>
                            <option value="daily" @selected(old('payment_period') === 'daily')>День</option>
                            <option value="monthly" @selected(old('payment_period') === 'monthly')>Месяц</option>
                            <option value="quarterly" @selected(old('payment_period') === 'quarterly')>3 месяца</option>
                            <option value="yearly" @selected(old('payment_period') === 'yearly')>Год</option>
                            <option value="package" @selected(old('payment_period') === 'package')>Пакет</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Сумма</label>
                        <input type="number"
                               name="amount"
                               class="form-control"
                               step="0.01"
                               min="0"
                               value="{{ old('amount') }}"
                               required>
                    </div>

                </div>
            </div>
        </div>

        {{-- TUITION --}}
        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-tuition">
            <div class="card-header fw-bold">🎓 Обучение</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Группа классов</label>
                        <select name="grade_group" class="form-select">
                            <option value="">-- Не указано --</option>
                            <option value="Подготовительный" @selected(old('grade_group') === 'Подготовительный')>Подготовительный</option>
                            <option value="1-4" @selected(old('grade_group') === '1-4')>1-4</option>
                            <option value="5-6" @selected(old('grade_group') === '5-6')>5-6</option>
                            <option value="7-8" @selected(old('grade_group') === '7-8')>7-8</option>
                            <option value="9-11" @selected(old('grade_group') === '9-11')>9-11</option>
                        </select>
                        <small class="text-muted">Например: 1-4, 5-6, 9-11.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Отдельный класс</label>
                        <select name="grade_id" class="form-select">
                            <option value="">-- Без класса --</option>

                            @foreach($grades as $grade)
                                <option value="{{ $grade->id }}" @selected(old('grade_id') == $grade->id)>
                                    {{ $grade->name ?? $grade->name_ru ?? 'Grade #' . $grade->id }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Можно оставить пустым, если цена по группе классов.</small>
                    </div>

                </div>
            </div>
        </div>

        {{-- TRANSPORT --}}
        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-transport">
            <div class="card-header fw-bold">🚌 Трансфер</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Район / зона</label>
                        <input type="text"
                               name="option_value"
                               class="form-control"
                               value="{{ old('option_value') }}"
                               placeholder="Например: Каусер, Муб 2, Интеркон-ль">
                        <input type="hidden" name="option_type" id="option_type" value="{{ old('option_type') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Пример</label>
                        <div class="alert alert-light border mb-0">
                            Каусер / Муб 2 / Интеркон-ль — 1500 в месяц
                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- UNIFORM --}}
        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-uniform">
            <div class="card-header fw-bold">👕 Школьная форма</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Размер</label>
                        <select name="size" class="form-select">
                            <option value="">-- Не указано --</option>
                            @foreach(['6-10','12-16','S+','8','10','12','14','16','S','M','L','XL','XXL'] as $size)
                                <option value="{{ $size }}" @selected(old('size') == $size)>
                                    {{ $size }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Предмет</label>
                        <select name="item" class="form-select">
                            <option value="">-- Не указано --</option>
                            <option value="tshirt" @selected(old('item') === 'tshirt')>Майка / T-shirt</option>
                            <option value="polo" @selected(old('item') === 'polo')>Поло</option>
                            <option value="jacket" @selected(old('item') === 'jacket')>Толстовка / Jacket</option>
                            <option value="full_set" @selected(old('item') === 'full_set')>Комплект</option>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        {{-- EXTRAS --}}
        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-extra">
            <div class="card-header fw-bold">📚 Допы</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Количество часов</label>
                        <input type="text"
                               name="extra_hours"
                               id="extra_hours"
                               class="form-control"
                               value="{{ old('extra_hours') }}"
                               placeholder="Например: 8">
                        <small class="text-muted">Для русского языка или математики: 1500 за 8 часов.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Комментарий</label>
                        <div class="alert alert-light border mb-0">
                            Можно указать в примечании: «Русский язык — 8 часов».
                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- FOOD --}}
        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-food">
            <div class="card-header fw-bold">🍽 Питание</div>

            <div class="card-body">
                <div class="alert alert-light border mb-0">
                    Примеры: питание в день — 170, завтрак — 70, обед — 100, напиток — 10.
                </div>
            </div>
        </div>

        {{-- DATES --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-bold">📅 Даты действия цены</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Дата начала</label>
                        <input type="date"
                               name="start_date"
                               class="form-control"
                               value="{{ old('start_date', now()->toDateString()) }}"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Дата окончания</label>
                        <input type="date"
                               name="end_date"
                               class="form-control"
                               value="{{ old('end_date') }}">
                        <small class="text-muted">Оставьте пустым, если цена действует без окончания.</small>
                    </div>

                </div>
            </div>
        </div>

        {{-- NOTES --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-bold">📝 Примечание</div>

            <div class="card-body">
                <textarea name="notes"
                          id="notes"
                          class="form-control"
                          rows="3"
                          placeholder="Например: цена с 2025-2026 учебного года">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('dashboard.fee-prices.index') }}" class="btn btn-light">
                Отмена
            </a>

            <button class="btn btn-primary">
                💾 Сохранить цену
            </button>
        </div>

    </form>

</div>

<style>
    .dynamic-block {
        display: none;
    }
</style>

<script>
    const feeSelect = document.getElementById('fee_id');
    const optionType = document.getElementById('option_type');
    const notes = document.getElementById('notes');
    const extraHours = document.getElementById('extra_hours');

    const blocks = {
        tuition: document.getElementById('block-tuition'),
        tuition_regular: document.getElementById('block-tuition'),
        tuition_family: document.getElementById('block-tuition'),
        tuition_external: document.getElementById('block-tuition'),
        transport: document.getElementById('block-transport'),
        uniform: document.getElementById('block-uniform'),
        extra_classes: document.getElementById('block-extra'),
        food: document.getElementById('block-food'),
    };

    function hideBlocks() {
        Object.values(blocks).forEach(block => {
            if (block) block.style.display = 'none';
        });
    }

    function selectedCategory() {
        const selected = feeSelect.options[feeSelect.selectedIndex];
        return selected?.dataset.category || '';
    }

    function updateUI() {
        hideBlocks();

        const category = selectedCategory();

        if (blocks[category]) {
            blocks[category].style.display = 'block';
        }

        if (optionType) {
            optionType.value = '';
        }

        if (category === 'transport' && optionType) {
            optionType.value = 'zone';
        }

        if (category === 'extra_classes' && optionType) {
            optionType.value = 'hours';
        }
    }

    if (feeSelect) {
        feeSelect.addEventListener('change', updateUI);
    }

    if (extraHours) {
        extraHours.addEventListener('input', function () {
            if (selectedCategory() === 'extra_classes') {
                const current = notes.value || '';
                if (!current || current.includes('час')) {
                    notes.value = 'Пакет — ' + extraHours.value + ' часов';
                }
            }
        });
    }

    updateUI();
</script>

@endsection