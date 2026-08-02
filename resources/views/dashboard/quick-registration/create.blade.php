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
@endphp
<div class="container-fluid py-4">
    <h2 class="mb-1">Быстрая регистрация нового ученика</h2>
    <p class="text-muted">Минимальное оформление и первоначальный счёт. Валюта расчётов: EGP.</p>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Проверьте введённые данные:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('dashboard.quick-registration.store') }}" id="quick-registration-form">
        @csrf
        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">1. Минимальные данные ученика</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label">Имя ученика на русском языке *</label><input name="student_name_ru" value="{{ old('student_name_ru') }}" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Имя ученика на английском языке</label><input name="student_name_en" value="{{ old('student_name_en') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Телефон *</label><input name="phone" value="{{ old('phone') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Дата регистрации *</label><input type="date" name="registration_date" value="{{ old('registration_date', now()->toDateString()) }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Учебный год *</label><select name="academic_year_id" class="form-select" required><option value="">Выберите учебный год</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string) old('academic_year_id', $defaultAcademicYearId) === (string) $year->id)>{{ $year->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Ступень *</label><select name="stage_id" id="stage" class="form-select" required><option value="">Выберите ступень</option>@foreach($stages as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Параллель *</label><select name="grade_id" id="grade" class="form-select" required><option value="">Выберите параллель</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)<option value="{{ $grade->id }}" data-stage="{{ $stage->id }}">{{ $grade->name }}</option>@endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Класс *</label><select name="class_id" id="school-class" class="form-select" required><option value="">Выберите класс</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)@foreach($grade->classes as $class)<option value="{{ $class->id }}" data-grade="{{ $grade->id }}">{{ $class->name_ru ?: $class->code }}</option>@endforeach @endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Форма обучения *</label><select name="enrollment_mode_id" class="form-select" required><option value="">Выберите форму обучения</option>@foreach($modes as $mode)<option value="{{ $mode->id }}">{{ $mode->name_ru }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">2. Финансовые услуги</div>
            <div class="card-body">
                @foreach($groups as $groupKey => $group)
                    <h5 class="mt-3 mb-3">{{ $group['title'] }}</h5>
                    @forelse($group['fees'] as $fee)
                        @php $index = $fees->search(fn ($candidate) => $candidate->id === $fee->id); @endphp
                        <div class="service-row border rounded p-3 mb-3" data-fee-id="{{ $fee->id }}" data-name="{{ $fee->name_ru }}" data-fallback-price="{{ $fee->current_amount }}">
                            <div class="form-check">
                                <input class="form-check-input service-toggle" type="checkbox" name="services[{{ $index }}][fee_id]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}">
                                <label class="form-check-label fw-bold" for="fee-{{ $fee->id }}">{{ $fee->name_ru }}</label>
                            </div>
                            <div class="service-fields row g-3 mt-1 d-none">
                                @if($groupKey !== 'uniform')<input type="hidden" name="services[{{ $index }}][quantity]" value="1" class="quantity">@endif
                                @if($groupKey === 'tuition')
                                    <div class="col-md-3"><label class="form-label">Группа классов</label><select name="services[{{ $index }}][grade_group]" class="form-select price-option"><option value="">По выбранному классу</option>@foreach($fee->prices->pluck('grade_group')->filter()->unique() as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Период оплаты</label><select name="services[{{ $index }}][payment_period]" class="form-select price-option"><option value="">Стандартный</option>@foreach($fee->prices->pluck('payment_period')->filter()->unique() as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3 form-check mt-5"><input type="checkbox" value="1" name="services[{{ $index }}][first_last_month]" class="form-check-input price-option" id="first-last-{{ $fee->id }}"><label for="first-last-{{ $fee->id }}">Первый и последний месяц</label></div>
                                @elseif($groupKey === 'transport')
                                    <div class="col-md-3"><label class="form-label">Район / зона *</label><select name="services[{{ $index }}][transport_area]" class="form-select price-option"><option value="">Выберите зону</option>@foreach($fee->prices->where('option_type', 'zone')->pluck('option_value')->filter()->unique() as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Маршрут *</label><select name="services[{{ $index }}][transport_route_id]" class="form-select"><option value="">Выберите маршрут</option>@foreach($transportRoutes as $route)<option value="{{ $route->id }}">{{ $route->name }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Остановка</label><input name="services[{{ $index }}][transport_stop]" class="form-control"></div>
                                @elseif($groupKey === 'food')
                                    <div class="col-md-4"><label class="form-label">План питания *</label><select name="services[{{ $index }}][meal_plan_id]" class="form-select price-option"><option value="">Выберите план питания</option>@foreach($mealPlans as $plan)<option value="{{ $plan->id }}">{{ $plan->name_ru }}</option>@endforeach</select></div>
                                @elseif($groupKey === 'uniform')
                                    <div class="col-md-4"><label class="form-label">Изделие и размер *</label><select name="services[{{ $index }}][uniform_product_id]" class="form-select price-option uniform-product"><option value="">Выберите изделие</option>@foreach($uniformProducts as $product)<option value="{{ $product->id }}" data-item="{{ $product->name_ru }}" data-size="{{ $product->size }}">{{ $product->name_ru }} — {{ $product->size }}</option>@endforeach</select></div>
                                    <div class="col-md-2"><label class="form-label">Количество *</label><input type="number" min="1" max="100" name="services[{{ $index }}][quantity]" value="1" class="form-control quantity"></div>
                                @endif
                                <div class="col-md-2"><label class="form-label">Цена</label><div class="resolved-unit fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Стоимость</label><div class="resolved-total fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Оплачено</label><input type="number" min="0" step="0.01" name="services[{{ $index }}][paid_now]" value="0.00" class="form-control paid-now"></div>
                                <div class="col-md-2"><label class="form-label">Остаток</label><div class="remaining fw-semibold">0.00 EGP</div></div>
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

        <section class="card shadow-sm mb-4"><div class="card-header fw-bold">4. Оплата</div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" class="form-select"><option value="">Без оплаты</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" class="form-select"><option value="">Без оплаты</option><option value="cash">Наличные</option><option value="card">Карта</option><option value="bank">Банк</option><option value="transfer">Перевод</option></select></div>
            <div class="col-md-4"><label class="form-label">Примечание к оплате</label><input name="payment_note" class="form-control"></div>
        </div></section>

        <button class="btn btn-primary btn-lg">Создать ученика и счёт</button>
    </form>
</div>

<script>
const money = value => `${Number(value || 0).toFixed(2)} EGP`;
const stage = document.getElementById('stage');
const grade = document.getElementById('grade');
const schoolClass = document.getElementById('school-class');
const filterAcademics = () => {
    grade.querySelectorAll('option[data-stage]').forEach(option => option.hidden = option.dataset.stage !== stage.value);
    if (grade.selectedOptions[0]?.hidden) grade.value = '';
    schoolClass.querySelectorAll('option[data-grade]').forEach(option => option.hidden = option.dataset.grade !== grade.value);
    if (schoolClass.selectedOptions[0]?.hidden) schoolClass.value = '';
};
stage.addEventListener('change', filterAcademics); grade.addEventListener('change', filterAcademics); filterAcademics();

const rows = [...document.querySelectorAll('.service-row')];
async function updateRow(row) {
    const selected = row.querySelector('.service-toggle').checked;
    const fields = row.querySelector('.service-fields');
    fields.classList.toggle('d-none', !selected);
    fields.querySelectorAll('input, select').forEach(field => field.disabled = !selected);
    if (!selected) { row.dataset.total = row.dataset.paid = row.dataset.remaining = '0'; updateSummary(); return; }

    const body = new FormData();
    body.append('_token', document.querySelector('input[name="_token"]').value);
    body.append('fee_id', row.dataset.feeId);
    body.append('quantity', row.querySelector('.quantity')?.value || 1);
    if (grade.value) body.append('grade_id', grade.value);
    ['grade_group', 'payment_period', 'transport_area'].forEach(name => { const input = row.querySelector(`[name$="[${name}]"]`); if (input?.value) body.append(name, input.value); });
    const firstLast = row.querySelector('[name$="[first_last_month]"]'); if (firstLast?.checked) body.append('first_last_month', '1');
    const mealPlan = row.querySelector('[name$="[meal_plan_id]"]'); if (mealPlan?.value) body.append('meal_plan_id', mealPlan.value);
    const product = row.querySelector('.uniform-product')?.selectedOptions[0]; if (product?.value) { body.append('item', product.dataset.item); body.append('size', product.dataset.size); }

    let unit = Number(row.dataset.fallbackPrice); let total = unit * Number(row.querySelector('.quantity')?.value || 1);
    const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {'Accept': 'application/json'}});
    if (response.ok) { const result = await response.json(); unit = Number(result.unit_price); total = Number(result.amount); }
    const paid = Number(row.querySelector('.paid-now')?.value || 0); const remaining = Math.max(total - paid, 0);
    row.dataset.total = total; row.dataset.paid = paid; row.dataset.remaining = remaining;
    row.querySelector('.resolved-unit').textContent = money(unit); row.querySelector('.resolved-total').textContent = money(total); row.querySelector('.remaining').textContent = money(remaining);
    updateSummary();
}
function updateSummary() {
    const tbody = document.querySelector('#live-summary tbody'); tbody.innerHTML = '';
    let total = 0, paid = 0, remaining = 0;
    rows.filter(row => row.querySelector('.service-toggle').checked).forEach(row => {
        total += Number(row.dataset.total || 0); paid += Number(row.dataset.paid || 0); remaining += Number(row.dataset.remaining || 0);
        tbody.insertAdjacentHTML('beforeend', `<tr><td>${row.dataset.name}</td><td>${money(row.dataset.total)}</td><td>${money(row.dataset.paid)}</td><td>${money(row.dataset.remaining)}</td></tr>`);
    });
    if (!tbody.children.length) tbody.innerHTML = '<tr><td colspan="4" class="text-muted">Услуги не выбраны.</td></tr>';
    [['summary-total', total], ['summary-paid', paid], ['summary-remaining', remaining], ['grand-total', total], ['grand-paid', paid], ['grand-remaining', remaining]].forEach(([id, value]) => document.getElementById(id).textContent = money(value));
}
rows.forEach(row => { row.querySelectorAll('input, select').forEach(input => { input.addEventListener('change', () => updateRow(row)); input.addEventListener('input', () => updateRow(row)); }); updateRow(row); });
grade.addEventListener('change', () => rows.filter(row => row.querySelector('.service-toggle').checked).forEach(updateRow));
</script>
@endsection
