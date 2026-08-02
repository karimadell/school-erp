@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <h2 class="mb-1">Новый ученик</h2>
    <p class="text-muted">Быстрая предварительная регистрация и первоначальный счёт в EGP.</p>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Проверьте данные:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('dashboard.quick-registration.store') }}">
        @csrf
        <div class="card mb-4"><div class="card-header">Минимальные данные ученика</div><div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Имя ученика на русском языке</label><input name="student_name_ru" value="{{ old('student_name_ru') }}" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Телефон</label><input name="phone" value="{{ old('phone') }}" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Дата регистрации</label><input type="date" name="registration_date" value="{{ old('registration_date', now()->toDateString()) }}" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Учебный год</label><select name="academic_year_id" class="form-select" required><option value="">Выберите</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Ступень</label><select name="stage_id" id="stage" class="form-select" required><option value="">Выберите</option>@foreach($stages as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Класс</label><select name="grade_id" id="grade" class="form-select" required><option value="">Выберите</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)<option value="{{ $grade->id }}" data-stage="{{ $stage->id }}">{{ $grade->name }}</option>@endforeach @endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Форма обучения</label><select name="enrollment_mode_id" class="form-select" required><option value="">Выберите</option>@foreach($modes as $mode)<option value="{{ $mode->id }}">{{ $mode->name_ru }}</option>@endforeach</select></div>
        </div></div>

        <div class="card mb-4"><div class="card-header">Финансовые услуги</div><div class="card-body">
            @foreach($fees as $index => $fee)
                <div class="border rounded p-3 mb-3 service-row" data-price="{{ $fee->current_amount }}" data-fee-id="{{ $fee->id }}">
                    <div class="form-check"><input class="form-check-input service-toggle" type="checkbox" name="services[{{ $index }}][fee_id]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}"><label class="form-check-label fw-bold" for="fee-{{ $fee->id }}">{{ $fee->name_ru }} — {{ number_format($fee->current_amount, 2) }} EGP</label></div>
                    <div class="row g-2 mt-2 service-fields">
                        <div class="col-md-2"><label>Количество</label><input type="number" min="1" max="100" name="services[{{ $index }}][quantity]" value="1" class="form-control quantity"></div>
                        <div class="col-md-2"><label>Оплатить сейчас</label><input type="number" min="0" step="0.01" name="services[{{ $index }}][paid_now]" value="0.00" class="form-control paid-now"></div>
                        <div class="col-md-2"><label>Стоимость</label><div class="form-control-plaintext resolved">0.00 EGP</div></div>
                        <div class="col-md-2"><label>Остаток</label><div class="form-control-plaintext remaining">0.00 EGP</div></div>
                        @if($fee->category === 'uniform')
                            <div class="col-md-2"><label>Изделие</label><input name="services[{{ $index }}][item]" class="form-control"></div><div class="col-md-2"><label>Размер</label><input name="services[{{ $index }}][size]" class="form-control"></div>
                        @elseif($fee->category === 'transport')
                            <div class="col-md-2"><label>Район</label><input name="services[{{ $index }}][transport_area]" class="form-control"></div><div class="col-md-2"><label>Маршрут</label><input name="services[{{ $index }}][transport_route]" class="form-control"></div><div class="col-md-2"><label>Остановка</label><input name="services[{{ $index }}][transport_stop]" class="form-control"></div>
                        @elseif($fee->category === 'food')
                            <div class="col-md-3"><label>План питания</label><select name="services[{{ $index }}][meal_plan_id]" class="form-select"><option value="">Выберите</option>@foreach($mealPlans as $plan)<option value="{{ $plan->id }}">{{ $plan->name_ru }}</option>@endforeach</select></div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div></div>

        <div class="card mb-4"><div class="card-header">Оплата</div><div class="card-body row g-3">
            <div class="col-md-4"><label>Касса</label><select name="cash_account_id" class="form-select"><option value="">Без оплаты</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
            <div class="col-md-4"><label>Способ оплаты</label><select name="payment_method" class="form-select"><option value="">Без оплаты</option><option value="cash">Наличные</option><option value="card">Карта</option><option value="bank">Банк</option><option value="transfer">Перевод</option></select></div>
        </div></div>
        <button class="btn btn-primary btn-lg">Создать ученика и счёт</button>
    </form>
</div>
<script>
document.querySelectorAll('.service-row').forEach(row => {
    const update = async () => {
        const selected = row.querySelector('.service-toggle').checked;
        row.querySelectorAll('.service-fields input, .service-fields select').forEach(field => field.disabled = ! selected);
        let total = selected ? Number(row.dataset.price) * Number(row.querySelector('.quantity').value || 0) : 0;
        if (selected) {
            const body = new FormData();
            body.append('_token', document.querySelector('input[name="_token"]').value);
            body.append('fee_id', row.dataset.feeId);
            body.append('quantity', row.querySelector('.quantity').value || 1);
            ['item', 'size', 'transport_area'].forEach(field => {
                const input = row.querySelector(`[name$="[${field}]"]`); if (input?.value) body.append(field, input.value);
            });
            const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {'Accept': 'application/json'}});
            if (response.ok) total = Number((await response.json()).amount);
        }
        const paid = selected ? Number(row.querySelector('.paid-now').value || 0) : 0;
        row.querySelector('.resolved').textContent = total.toFixed(2) + ' EGP';
        row.querySelector('.remaining').textContent = Math.max(total - paid, 0).toFixed(2) + ' EGP';
    };
    row.querySelectorAll('input').forEach(input => input.addEventListener('input', update)); update();
});
document.getElementById('stage').addEventListener('change', event => document.querySelectorAll('#grade option[data-stage]').forEach(option => option.hidden = option.dataset.stage !== event.target.value));
</script>
@endsection
