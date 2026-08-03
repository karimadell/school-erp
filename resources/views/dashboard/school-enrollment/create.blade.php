@extends('layouts.dashboard')

@section('content')
<div class="mb-4"><h1 class="h3 mb-1">Зачисление ученика</h1><p class="text-muted mb-0">Заполните пять шагов. Данные будут сохранены только после подтверждения.</p></div>

@if($errors->any())
    <div class="alert alert-danger"><strong>Проверьте заполнение формы.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="progress mb-3" style="height:8px"><div class="progress-bar" data-wizard-progress style="width:20%"></div></div>
    <div class="d-flex justify-content-between small text-muted gap-2">
        @foreach(['Ученик','Родители','Учёба','Услуги','Проверка'] as $label)<span data-step-label="{{ $loop->iteration }}" class="{{ $loop->first ? 'fw-bold text-primary' : '' }}">{{ $loop->iteration }}. {{ $label }}</span>@endforeach
    </div>
</div></div>

<form method="POST" action="{{ route('dashboard.school-enrollment.store') }}" enctype="multipart/form-data" data-enrollment-wizard>
    @csrf
    <section data-wizard-step="1" class="card border-0 shadow-sm"><div class="card-body p-4">
        <h2 class="h5 mb-4">Шаг 1 — Ученик</h2>
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Полное имя на русском языке *</label><input class="form-control" name="student_name_ru" required value="{{ old('student_name_ru') }}"></div>
            <div class="col-md-6"><label class="form-label">Имя на английском языке</label><input class="form-control" name="student_name_en" value="{{ old('student_name_en') }}"></div>
            <div class="col-md-6"><label class="form-label">Имя на арабском языке</label><input class="form-control" name="student_name_ar" value="{{ old('student_name_ar') }}"></div>
            <div class="col-md-4"><label class="form-label">Пол</label><select class="form-select" name="gender"><option value="">Не выбран</option><option value="male" @selected(old('gender')==='male')>Мужской</option><option value="female" @selected(old('gender')==='female')>Женский</option></select></div>
            <div class="col-md-4"><label class="form-label">Дата рождения</label><input class="form-control" type="date" name="birth_date" value="{{ old('birth_date') }}"></div>
            <div class="col-md-4"><label class="form-label">Гражданство</label><input class="form-control" name="nationality" value="{{ old('nationality') }}"></div>
            <div class="col-md-6"><label class="form-label">Паспорт / свидетельство о рождении</label><input class="form-control" name="identity_document" value="{{ old('identity_document') }}"></div>
            <div class="col-md-6"><label class="form-label">Фотография ученика</label><input class="form-control" type="file" name="photo" accept="image/png,image/jpeg,image/webp"><div class="form-text">JPG, PNG или WEBP, не более 2 МБ.</div></div>
        </div>
    </div></section>

    <section data-wizard-step="2" class="card border-0 shadow-sm d-none"><div class="card-body p-4">
        <h2 class="h5 mb-4">Шаг 2 — Родители</h2>
        <div class="row g-4">
            @foreach(['father'=>'Отец','mother'=>'Мать'] as $prefix=>$title)
                <div class="col-12 col-xl-6"><div class="border rounded p-3 h-100"><h3 class="h6 mb-3">{{ $title }}</h3><div class="row g-3">
                    <div class="col-12"><label class="form-label">ФИО</label><input class="form-control" name="{{ $prefix }}_name" value="{{ old($prefix.'_name') }}"></div>
                    <div class="col-md-6"><label class="form-label">Телефон</label><input class="form-control" name="{{ $prefix }}_phone" value="{{ old($prefix.'_phone') }}"></div>
                    <div class="col-md-6"><label class="form-label">Электронная почта</label><input class="form-control" type="email" name="{{ $prefix }}_email" value="{{ old($prefix.'_email') }}"></div>
                    <div class="col-12"><label class="form-label">Паспорт</label><input class="form-control" name="{{ $prefix }}_passport" value="{{ old($prefix.'_passport') }}"></div>
                </div></div></div>
            @endforeach
            <div class="col-12"><label class="form-label">Контакт для экстренной связи</label><textarea class="form-control" name="emergency_contact" rows="2">{{ old('emergency_contact') }}</textarea></div>
        </div>
    </div></section>

    <section data-wizard-step="3" class="card border-0 shadow-sm d-none"><div class="card-body p-4">
        <h2 class="h5 mb-4">Шаг 3 — Учебные данные</h2>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Учебный год *</label><select class="form-select" name="academic_year_id" required>@forelse($academicYears as $year)<option value="{{ $year->id }}" @selected(old('academic_year_id',$academicYears->count()===1 ? $year->id : null)==$year->id)>{{ $year->name }}</option>@empty<option value="">Нет активного учебного года</option>@endforelse</select></div>
            <div class="col-md-6"><label class="form-label">Форма обучения *</label><select class="form-select" name="enrollment_mode_id" required><option value="">Выберите форму обучения</option>@foreach($enrollmentModes as $mode)<option value="{{ $mode->id }}" @selected(old('enrollment_mode_id',$enrollmentModes->count()===1 ? $mode->id : null)==$mode->id)>{{ $mode->name_ru }}</option>@endforeach</select>@if($enrollmentModes->isEmpty())<div class="text-danger small mt-1">Формы обучения не настроены.</div>@endif</div>
            <div class="col-md-6"><label class="form-label">Ступень *</label><select class="form-select" name="stage_id" required><option value="">Выберите ступень</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" @selected(old('stage_id')==$stage->id)>{{ $stage->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">Класс *</label><select class="form-select" name="grade_id" required><option value="">Сначала выберите ступень</option></select></div>
            <div class="col-md-6"><label class="form-label">Учебная группа *</label><select class="form-select" name="class_id" required><option value="">Сначала выберите класс</option></select></div>
        </div>
    </div></section>

    <section data-wizard-step="4" class="card border-0 shadow-sm d-none"><div class="card-body p-4">
        <h2 class="h5 mb-2">Шаг 4 — Услуги</h2><p class="text-muted">Цены загружены из действующих тарифов FeePrice. Ручной ввод цены недоступен.</p>
        @forelse($pricesByCategory as $category => $prices)
            <div class="mb-4"><h3 class="h6 text-uppercase mb-3">{{ $category }}</h3><div class="row g-3">
                @foreach($prices as $price)
                    <div class="col-12 col-md-6 col-xl-4"><label class="card h-100 border shadow-sm p-3" style="cursor:pointer">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="fee_price_ids[]" value="{{ $price->id }}" data-service-price="{{ $price->amount }}" data-service-name="{{ $price->display_name }}" @checked(in_array($price->id, old('fee_price_ids', [])))><span class="form-check-label fw-semibold">{{ $price->fee->name_ru }}</span></div>
                        <div class="small text-muted mt-2">{{ collect([$price->grade_group, $price->payment_period, $price->item, $price->size, $price->option_value])->filter()->implode(' · ') ?: 'Основной тариф' }}</div>
                        @if($category === 'Транспорт')<div class="small mt-2 d-none" data-transport-options>Транспортная зона: {{ $price->option_value ?: 'не указана' }}</div>@endif
                        @if($category === 'Питание')<div class="small mt-2 d-none" data-meal-options>План питания: {{ $price->item ?: $price->option_value ?: 'не указан' }}</div>@endif
                        @if($category === 'Школьная форма')<div class="small mt-2 d-none" data-uniform-options>Комплект: {{ $price->item ?: 'не указан' }}@if($price->size), размер {{ $price->size }}@endif</div>@endif
                        <div class="mt-auto pt-3 fs-5 fw-bold">{{ number_format((float)$price->amount,2,'.',' ') }} EGP</div>
                    </label></div>
                @endforeach
            </div></div>
        @empty<div class="alert alert-warning">Для активного учебного года услуги и тарифы не настроены.</div>@endforelse
        <div class="border rounded bg-light p-3 d-flex justify-content-between"><strong>Общая сумма</strong><strong><span data-services-total>0.00</span> EGP</strong></div>
    </div></section>

    <section data-wizard-step="5" class="card border-0 shadow-sm d-none"><div class="card-body p-4">
        <h2 class="h5 mb-4">Шаг 5 — Проверка</h2>
        <div class="row g-3">
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Ученик</h3><div data-review-student>—</div></div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Родители</h3><div data-review-parents>—</div></div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Учебные данные</h3><div data-review-academic>—</div></div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6">Услуги</h3><div data-review-services>—</div></div></div>
        </div>
        <div class="alert alert-primary mt-4 mb-0 d-flex justify-content-between"><strong>Итоговая сумма</strong><strong><span data-review-total>0.00</span> EGP</strong></div>
    </div></section>

    <div class="d-flex justify-content-between mt-4">
        <button type="button" class="btn btn-outline-secondary d-none" data-wizard-back>Назад</button>
        <button type="button" class="btn btn-primary ms-auto" data-wizard-next>Далее</button>
        <button type="submit" class="btn btn-success ms-auto d-none" data-wizard-submit @disabled($academicYears->isEmpty() || $pricesByCategory->isEmpty())>Завершить зачисление</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var step = 1;
    var steps = document.querySelectorAll('[data-wizard-step]');
    var back = document.querySelector('[data-wizard-back]');
    var next = document.querySelector('[data-wizard-next]');
    var submit = document.querySelector('[data-wizard-submit]');
    var stageData = @json($structureData);
    var oldGrade = @json((string) old('grade_id'));
    var oldClass = @json((string) old('class_id'));

    function showStep(number) {
        step = number;
        steps.forEach(function (panel) { panel.classList.toggle('d-none', Number(panel.dataset.wizardStep) !== step); });
        document.querySelector('[data-wizard-progress]').style.width = (step * 20) + '%';
        document.querySelectorAll('[data-step-label]').forEach(function (label) { label.classList.toggle('fw-bold', Number(label.dataset.stepLabel) === step); label.classList.toggle('text-primary', Number(label.dataset.stepLabel) === step); });
        back.classList.toggle('d-none', step === 1);
        next.classList.toggle('d-none', step === 5);
        submit.classList.toggle('d-none', step !== 5);
        if (step === 5) refreshReview();
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    next.addEventListener('click', function () {
        var required = document.querySelector('[data-wizard-step="' + step + '"] [required]:invalid');
        if (required) { required.reportValidity(); return; }
        if (step === 4 && !document.querySelector('[data-service-price]:checked')) { alert('Выберите хотя бы одну услугу.'); return; }
        showStep(Math.min(5, step + 1));
    });
    back.addEventListener('click', function () { showStep(Math.max(1, step - 1)); });

    var stage = document.querySelector('[name="stage_id"]');
    var grade = document.querySelector('[name="grade_id"]');
    var schoolClass = document.querySelector('[name="class_id"]');
    function loadGrades() {
        var grades = stageData[stage.value] || [];
        grade.innerHTML = '<option value="">Выберите класс</option>' + grades.map(function (item) { return '<option value="' + item.id + '">' + item.name + '</option>'; }).join('');
        if (grades.some(function(item){ return String(item.id) === oldGrade; })) grade.value = oldGrade;
        loadClasses();
    }
    function loadClasses() {
        var grades = stageData[stage.value] || [];
        var selected = grades.find(function (item) { return String(item.id) === grade.value; });
        var classes = selected ? selected.classes : [];
        schoolClass.innerHTML = '<option value="">Выберите учебную группу</option>' + classes.map(function (item) { return '<option value="' + item.id + '">' + item.name + '</option>'; }).join('');
        if (classes.some(function(item){ return String(item.id) === oldClass; })) schoolClass.value = oldClass;
    }
    stage.addEventListener('change', function(){ oldGrade=''; oldClass=''; loadGrades(); });
    grade.addEventListener('change', function(){ oldClass=''; loadClasses(); });
    loadGrades();

    function total() {
        return Array.from(document.querySelectorAll('[data-service-price]:checked')).reduce(function(sum, item){ return sum + Math.round(Number(item.dataset.servicePrice) * 100); }, 0);
    }
    function refreshServices() {
        document.querySelectorAll('[data-service-price]').forEach(function (box) {
            var card = box.closest('.card');
            ['[data-transport-options]','[data-meal-options]','[data-uniform-options]'].forEach(function(selector){ card.querySelector(selector)?.classList.toggle('d-none', !box.checked); });
        });
        document.querySelector('[data-services-total]').textContent = (total()/100).toFixed(2);
    }
    document.querySelectorAll('[data-service-price]').forEach(function(box){ box.addEventListener('change', refreshServices); });
    refreshServices();

    function value(name) { return document.querySelector('[name="' + name + '"]')?.value || ''; }
    function selected(name) { var field=document.querySelector('[name="' + name + '"]'); return field?.options[field.selectedIndex]?.text || ''; }
    function refreshReview() {
        document.querySelector('[data-review-student]').textContent = value('student_name_ru');
        document.querySelector('[data-review-parents]').textContent = [value('father_name'), value('mother_name'), value('emergency_contact')].filter(Boolean).join(' · ') || 'Не указаны';
        document.querySelector('[data-review-academic]').textContent = [selected('academic_year_id'), selected('stage_id'), selected('grade_id'), selected('class_id')].filter(Boolean).join(' · ');
        var services = Array.from(document.querySelectorAll('[data-service-price]:checked')).map(function(item){ return item.dataset.serviceName; });
        document.querySelector('[data-review-services]').textContent = services.join(', ') || 'Не выбраны';
        document.querySelector('[data-review-total]').textContent = (total()/100).toFixed(2);
    }
})();
</script>
@endpush
