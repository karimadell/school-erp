@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">✏️ Редактировать цену</h3>
            <small class="text-muted">Изменение цены услуги</small>
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

    <form method="POST" action="{{ route('dashboard.fee-prices.update', $feePrice) }}">
        @csrf
        @method('PUT')

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
                                        @selected(old('fee_id', $feePrice->fee_id) == $fee->id)>
                                    {{ $fee->name_ru }}
                                    @if($fee->category)
                                        — {{ $fee->category }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Период оплаты</label>
                        <select name="payment_period" class="form-select">
                            <option value="">-- Не указано --</option>
                            <option value="once" @selected(old('payment_period', $feePrice->payment_period) === 'once')>Один раз</option>
                            <option value="daily" @selected(old('payment_period', $feePrice->payment_period) === 'daily')>День</option>
                            <option value="monthly" @selected(old('payment_period', $feePrice->payment_period) === 'monthly')>Месяц</option>
                            <option value="quarterly" @selected(old('payment_period', $feePrice->payment_period) === 'quarterly')>3 месяца</option>
                            <option value="yearly" @selected(old('payment_period', $feePrice->payment_period) === 'yearly')>Год</option>
                            <option value="package" @selected(old('payment_period', $feePrice->payment_period) === 'package')>Пакет</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Сумма</label>
                        <input type="number"
                               name="amount"
                               class="form-control"
                               step="0.01"
                               min="0"
                               value="{{ old('amount', $feePrice->amount) }}"
                               required>
                    </div>

                </div>
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-tuition">
            <div class="card-header fw-bold">🎓 Обучение</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Группа классов</label>
                        <select name="grade_group" class="form-select">
                            <option value="">-- Не указано --</option>
                            @foreach(['Подготовительный','1-4','5-6','7-8','9-11'] as $group)
                                <option value="{{ $group }}" @selected(old('grade_group', $feePrice->grade_group) === $group)>
                                    {{ $group }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Отдельный класс</label>
                        <select name="grade_id" class="form-select">
                            <option value="">-- Без класса --</option>

                            @foreach($grades as $grade)
                                <option value="{{ $grade->id }}" @selected(old('grade_id', $feePrice->grade_id) == $grade->id)>
                                    {{ $grade->name ?? $grade->name_ru ?? 'Grade #' . $grade->id }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-transport">
            <div class="card-header fw-bold">🚌 Трансфер</div>

            <div class="card-body">
                <label class="form-label">Район / зона</label>
                <input type="text"
                       name="option_value"
                       class="form-control"
                       value="{{ old('option_value', $feePrice->option_value) }}"
                       placeholder="Например: Каусер, Муб 2, Интеркон-ль">

                <input type="hidden"
                       name="option_type"
                       id="option_type"
                       value="{{ old('option_type', $feePrice->option_type) }}">
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-uniform">
            <div class="card-header fw-bold">👕 Школьная форма</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Размер</label>
                        <select name="size" class="form-select">
                            <option value="">-- Не указано --</option>
                            @foreach(['6-10','12-16','S+','8','10','12','14','16','S','M','L','XL','XXL'] as $size)
                                <option value="{{ $size }}" @selected(old('size', $feePrice->size) == $size)>
                                    {{ $size }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Предмет</label>
                        <select name="item" class="form-select">
                            <option value="">-- Не указано --</option>
                            <option value="tshirt" @selected(old('item', $feePrice->item) === 'tshirt')>Майка / T-shirt</option>
                            <option value="polo" @selected(old('item', $feePrice->item) === 'polo')>Поло</option>
                            <option value="jacket" @selected(old('item', $feePrice->item) === 'jacket')>Толстовка / Jacket</option>
                            <option value="full_set" @selected(old('item', $feePrice->item) === 'full_set')>Комплект</option>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-extra">
            <div class="card-header fw-bold">📚 Допы</div>

            <div class="card-body">
                <label class="form-label">Количество часов</label>
                <input type="text"
                       name="extra_hours"
                       id="extra_hours"
                       class="form-control"
                       value="{{ old('extra_hours', $feePrice->option_type === 'hours' ? $feePrice->option_value : '') }}"
                       placeholder="Например: 8">
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0 dynamic-block" id="block-food">
            <div class="card-header fw-bold">🍽 Питание</div>

            <div class="card-body">
                <div class="alert alert-light border mb-0">
                    Примеры: питание в день — 170, завтрак — 70, обед — 100, напиток — 10.
                </div>
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-bold">📅 Даты действия цены</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Дата начала</label>
                        <input type="date"
                               name="start_date"
                               class="form-control"
                               value="{{ old('start_date', $feePrice->start_date?->format('Y-m-d')) }}"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Дата окончания</label>
                        <input type="date"
                               name="end_date"
                               class="form-control"
                               value="{{ old('end_date', $feePrice->end_date?->format('Y-m-d')) }}">
                        <small class="text-muted">Оставьте пустым, если цена действует без окончания.</small>
                    </div>

                </div>
            </div>
        </div>

        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-bold">📝 Примечание и статус</div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-9">
                        <label class="form-label">Примечание</label>
                        <textarea name="notes"
                                  id="notes"
                                  class="form-control"
                                  rows="3">{{ old('notes', $feePrice->notes) }}</textarea>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Статус</label>
                        <select name="is_active" class="form-select">
                            <option value="1" @selected(old('is_active', $feePrice->is_active) == 1)>Активно</option>
                            <option value="0" @selected(old('is_active', $feePrice->is_active) == 0)>Не активно</option>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('dashboard.fee-prices.index') }}" class="btn btn-light">
                Отмена
            </a>

            <button class="btn btn-primary">
                💾 Сохранить изменения
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

    const blocks = {
        tuition: document.getElementById('block-tuition'),
        tuition_regular: document.getElementById('block-tuition'),
        tuition_family: document.getElementById('block-tuition'),
        tuition_external: document.getElementById('block-tuition'),
        transport: document.getElementById('block-transport'),
        uniform: document.getElementById('block-uniform'),
        extra_classes: document.getElementById('block-extra'),
        food: document.getElementById('block-food'),
        registration: null,
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
            if (category === 'transport') {
                optionType.value = 'zone';
            } else if (category === 'extra_classes') {
                optionType.value = 'hours';
            }
        }
    }

    if (feeSelect) {
        feeSelect.addEventListener('change', updateUI);
    }

    updateUI();
</script>

@endsection