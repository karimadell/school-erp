@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-2">
    <div class="mb-4">
        <h1 class="h3 mb-1">Настройки школы</h1>
        <p class="text-muted mb-0">Единые сведения для интерфейса и всех печатных документов.</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger"><strong>Проверьте заполнение формы.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('dashboard.settings.school.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach(['general'=>'Основные','contacts'=>'Контакты','documents'=>'Документы','finance'=>'Финансы','academic'=>'Учебный год'] as $key=>$label)
                <li class="nav-item"><button class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#settings-{{ $key }}" type="button">{{ $label }}</button></li>
            @endforeach
        </ul>

        <div class="tab-content card card-body shadow-sm">
            <div class="tab-pane fade show active" id="settings-general">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Название школы</label><input class="form-control" name="school_name" required value="{{ old('school_name',$settings->school_name) }}"></div>
                    <div class="col-md-6"><label class="form-label">Краткое название</label><input class="form-control" name="short_name" required value="{{ old('short_name',$settings->short_name) }}"></div>
                    <div class="col-md-6"><label class="form-label">Логотип</label><input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><div class="form-text">Текущий логотип автоматически используется во всех документах.</div></div>
                    <div class="col-md-6"><label class="form-label">Страна</label><input class="form-control" name="country" required value="{{ old('country',$settings->country) }}"></div>
                    <div class="col-md-4"><label class="form-label">Город</label><input class="form-control" name="city" value="{{ old('city',$settings->city) }}"></div>
                    <div class="col-md-4"><label class="form-label">Часовой пояс</label><input class="form-control" name="timezone" required value="{{ old('timezone',$settings->timezone) }}"></div>
                    <div class="col-md-4"><label class="form-label">Язык</label><select class="form-select" name="language"><option value="ru">Русский</option></select></div>
                </div>
            </div>

            <div class="tab-pane fade" id="settings-contacts"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Телефон 1</label><input class="form-control" name="phone_1" required value="{{ old('phone_1',$settings->phone_1) }}"></div>
                <div class="col-md-6"><label class="form-label">Телефон 2</label><input class="form-control" name="phone_2" value="{{ old('phone_2',$settings->phone_2) }}"></div>
                <div class="col-md-6"><label class="form-label">Электронная почта</label><input class="form-control" type="email" name="email" required value="{{ old('email',$settings->email) }}"></div>
                <div class="col-md-6"><label class="form-label">Сайт</label><input class="form-control" type="url" name="website" value="{{ old('website',$settings->website) }}"></div>
                <div class="col-12"><label class="form-label">Адрес</label><textarea class="form-control" name="address" rows="3">{{ old('address',$settings->address) }}</textarea></div>
            </div></div>

            <div class="tab-pane fade" id="settings-documents"><div class="row g-3">
                <div class="col-md-4"><label class="form-label">Логотип для печати</label><input class="form-control" type="file" name="printing_logo" accept="image/png,image/jpeg,image/webp"></div>
                <div class="col-md-4"><label class="form-label">Печать школы</label><input class="form-control" type="file" name="stamp" accept="image/png,image/jpeg,image/webp"></div>
                <div class="col-md-4"><label class="form-label">Подпись директора</label><input class="form-control" type="file" name="director_signature" accept="image/png,image/jpeg,image/webp"></div>
                <div class="col-md-3"><label class="form-label">Цвет заголовка</label><input class="form-control form-control-color" type="color" name="header_color" value="{{ old('header_color',$settings->header_color) }}"></div>
                <div class="col-md-3"><label class="form-label">Цвет подвала</label><input class="form-control form-control-color" type="color" name="footer_color" value="{{ old('footer_color',$settings->footer_color) }}"></div>
                <div class="col-md-3 form-check mt-5"><input type="hidden" name="print_date_enabled" value="0"><input class="form-check-input" type="checkbox" name="print_date_enabled" value="1" @checked(old('print_date_enabled',$settings->print_date_enabled))><label class="form-check-label">Показывать дату печати</label></div>
                <div class="col-md-3 form-check mt-5"><input type="hidden" name="page_numbers_enabled" value="0"><input class="form-check-input" type="checkbox" name="page_numbers_enabled" value="1" @checked(old('page_numbers_enabled',$settings->page_numbers_enabled))><label class="form-check-label">Показывать номера страниц</label></div>
            </div></div>

            <div class="tab-pane fade" id="settings-finance"><div class="row g-3">
                <div class="col-md-3"><label class="form-label">Валюта</label><select class="form-select" name="currency"><option value="EGP">EGP</option></select></div>
                <div class="col-md-3"><label class="form-label">Обозначение валюты</label><input class="form-control" name="currency_symbol" value="{{ old('currency_symbol',$settings->currency_symbol) }}"></div>
                <div class="col-md-3"><label class="form-label">Знаков после запятой</label><input class="form-control" type="number" min="0" max="4" name="decimal_places" value="{{ old('decimal_places',$settings->decimal_places) }}"></div>
                <div class="col-md-3"><label class="form-label">Формат суммы</label><select class="form-select" name="amount_format">@foreach(['1 234.56','1,234.56','1.234,56'] as $format)<option @selected(old('amount_format',$settings->amount_format)===$format)>{{ $format }}</option>@endforeach</select></div>
            </div></div>

            <div class="tab-pane fade" id="settings-academic"><div class="row g-3">
                <div class="col-md-4"><label class="form-label">Учебный год по умолчанию</label><select class="form-select" name="default_academic_year_id"><option value="">Не выбран</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected(old('default_academic_year_id',$settings->default_academic_year_id)==$year->id)>{{ $year->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Начало учебного года</label><input class="form-control" type="date" name="school_year_start" value="{{ old('school_year_start',$settings->school_year_start?->format('Y-m-d')) }}"></div>
                <div class="col-md-4"><label class="form-label">Окончание учебного года</label><input class="form-control" type="date" name="school_year_end" value="{{ old('school_year_end',$settings->school_year_end?->format('Y-m-d')) }}"></div>
            </div></div>
        </div>

        <button class="btn btn-primary mt-3">Сохранить настройки</button>
    </form>
</div>
@endsection
