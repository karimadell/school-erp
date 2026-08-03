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
                    <div class="col-md-6">
                        <label class="form-label">Логотип</label>
                        <div class="border rounded p-3 mb-2 text-center bg-light" data-asset-preview="logo">
                            <div class="small fw-semibold mb-2">Текущий логотип</div>
                            <img src="{{ $settings->logoUrl() ?? '' }}" alt="Текущий логотип школы" class="{{ $settings->logoUrl() ? '' : 'd-none' }} mx-auto" data-asset-image="logo" style="display:block;max-width:100%;max-height:110px;width:auto;height:auto;object-fit:contain">
                            <div class="{{ $settings->logoUrl() ? 'd-none' : '' }} text-muted small py-3" data-asset-placeholder="logo">Изображение не загружено. В документах будет показано название школы.</div>
                        </div>
                        <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/webp" data-branding-file="logo">
                        <div class="form-text">Допустимый размер файла: не более 2 МБ.<br>Форматы: JPG, JPEG, PNG, WEBP.</div>
                        <div class="text-danger small mt-1 d-none" data-branding-error="logo">Размер файла не должен превышать 2 МБ.</div>
                    </div>
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

            <div class="tab-pane fade" id="settings-documents">
                @php
                    $documentAssets = [
                        'printing_logo' => ['label' => 'Логотип школы для документов', 'current' => 'Текущий логотип для документов', 'url' => $settings->printingLogoUrl()],
                        'stamp' => ['label' => 'Официальная печать школы', 'current' => 'Текущая печать', 'url' => $settings->stampUrl()],
                        'director_signature' => ['label' => 'Подпись директора', 'current' => 'Текущая подпись директора', 'url' => $settings->directorSignatureUrl()],
                    ];
                @endphp
                <div class="row g-4 align-items-stretch" data-document-upload-row>
                    @foreach ($documentAssets as $field => $asset)
                        <div class="col-12 col-md-6 col-xl-4 d-flex">
                            <div class="card border shadow-sm w-100 h-100">
                                <div class="card-body d-flex flex-column">
                                    <label class="form-label fw-semibold">{{ $asset['label'] }}</label>
                                    <div class="border rounded p-3 mb-3 text-center bg-light d-flex flex-column justify-content-center" data-asset-preview="{{ $field }}" style="min-height:220px">
                                        <div class="small fw-semibold mb-2">{{ $asset['current'] }}</div>
                                        <img src="{{ $asset['url'] ?? '' }}" alt="{{ $asset['current'] }}" class="{{ $asset['url'] ? '' : 'd-none' }} mx-auto" data-asset-image="{{ $field }}" style="display:block;max-width:100%;max-height:180px;width:auto;height:auto;object-fit:contain">
                                        <div class="{{ $asset['url'] ? 'd-none' : '' }} text-muted small py-4" data-asset-placeholder="{{ $field }}">Изображение не загружено.</div>
                                    </div>
                                    <div class="mt-auto">
                                        <input class="form-control" type="file" name="{{ $field }}" accept="image/png,image/jpeg,image/webp" data-branding-file="{{ $field }}">
                                        <div class="form-text">Допустимый размер файла: не более 2 МБ.<br>Форматы: JPG, JPEG, PNG, WEBP.</div>
                                        <div class="text-danger small mt-1 d-none" data-branding-error="{{ $field }}">Размер файла не должен превышать 2 МБ.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="row g-3 mt-3" data-document-colors-row>
                    <div class="col-12 col-md-6"><label class="form-label">Цвет заголовка</label><input class="form-control form-control-color" type="color" name="header_color" value="{{ old('header_color',$settings->header_color) }}"></div>
                    <div class="col-12 col-md-6"><label class="form-label">Цвет подвала</label><input class="form-control form-control-color" type="color" name="footer_color" value="{{ old('footer_color',$settings->footer_color) }}"></div>
                </div>

                <div class="row g-3 mt-2" data-document-options-row>
                    <div class="col-12 col-md-6">
                        <div class="form-check border rounded p-3 ps-5 h-100">
                            <input type="hidden" name="print_date_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="print_date_enabled" value="1" @checked(old('print_date_enabled',$settings->print_date_enabled))>
                            <label class="form-check-label">Показывать дату печати</label>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-check border rounded p-3 ps-5 h-100">
                            <input type="hidden" name="page_numbers_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="page_numbers_enabled" value="1" @checked(old('page_numbers_enabled',$settings->page_numbers_enabled))>
                            <label class="form-check-label">Показывать номера страниц</label>
                        </div>
                    </div>
                </div>

                @php($documentLogoUrl = $settings->printingLogoUrl() ?: $settings->logoUrl())
                <div class="mt-5" data-document-preview-row>
                    <div class="card border shadow-sm overflow-hidden" data-document-preview>
                        <div class="card-header bg-white"><h2 class="h5 mb-0">Предварительный просмотр документа</h2></div>
                        <div class="card-body p-3 p-md-4">
                            <div class="rounded border bg-white mx-auto overflow-hidden" style="max-width:900px">
                                <div class="p-3 p-md-4 text-center" data-preview-header style="border-top:8px solid {{ old('header_color',$settings->header_color) }}">
                                    <img src="{{ $documentLogoUrl ?? '' }}" alt="Логотип в документе" class="{{ $documentLogoUrl ? '' : 'd-none' }} mx-auto mb-3" data-preview-logo style="display:block;max-width:100%;max-height:100px;width:auto;height:auto;object-fit:contain">
                                    <div class="{{ $documentLogoUrl ? 'd-none' : '' }} text-muted small mb-3" data-preview-logo-placeholder>Логотип не загружен — будет использовано название школы.</div>
                                    <div class="h4 mb-2 text-break" data-preview-school-name>{{ old('school_name',$settings->school_name) }}</div>
                                    <div class="small text-muted text-break" data-preview-phones>Тел.: {{ old('phone_1',$settings->phone_1) }}@if(old('phone_2',$settings->phone_2)) / {{ old('phone_2',$settings->phone_2) }}@endif</div>
                                    <div class="small text-muted text-break" data-preview-email>Email: {{ old('email',$settings->email) }}</div>
                                </div>
                                <div class="p-4 text-center text-muted">Здесь будет содержимое официального документа.</div>
                                <div class="p-3 text-center small" data-preview-footer style="border-bottom:8px solid {{ old('footer_color',$settings->footer_color) }}">
                                    <span class="{{ old('print_date_enabled',$settings->print_date_enabled) ? '' : 'd-none' }} d-block" data-preview-print-date data-enabled="{{ old('print_date_enabled',$settings->print_date_enabled) ? '1' : '0' }}">Дата печати: {{ now()->format('d.m.Y') }}</span>
                                    <span class="{{ old('page_numbers_enabled',$settings->page_numbers_enabled) ? '' : 'd-none' }} d-block" data-preview-page-numbers data-enabled="{{ old('page_numbers_enabled',$settings->page_numbers_enabled) ? '1' : '0' }}">Страница 1 из 1</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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

@push('scripts')
<script>
    (function () {
        var maximumSize = 2 * 1024 * 1024;
        var documentLogo = document.querySelector('[data-preview-logo]');
        var documentLogoPlaceholder = document.querySelector('[data-preview-logo-placeholder]');

        document.querySelectorAll('[data-branding-file]').forEach(function (input) {
            input.addEventListener('change', function () {
                var field = input.dataset.brandingFile;
                var file = input.files && input.files[0];
                var error = document.querySelector('[data-branding-error="' + field + '"]');
                var tooLarge = file && file.size > maximumSize;

                error?.classList.toggle('d-none', !tooLarge);
                if (tooLarge) {
                    input.value = '';
                    return;
                }
                if (!file) return;

                var reader = new FileReader();
                reader.addEventListener('load', function () {
                    var image = document.querySelector('[data-asset-image="' + field + '"]');
                    var placeholder = document.querySelector('[data-asset-placeholder="' + field + '"]');
                    if (image) {
                        image.src = reader.result;
                        image.classList.remove('d-none');
                    }
                    placeholder?.classList.add('d-none');

                    if (field === 'logo' || field === 'printing_logo') {
                        documentLogo.src = reader.result;
                        documentLogo.classList.remove('d-none');
                        documentLogoPlaceholder?.classList.add('d-none');
                    }
                });
                reader.readAsDataURL(file);
            });
        });

        function textValue(name) {
            return document.querySelector('[name="' + name + '"]')?.value.trim() || '';
        }

        function refreshDocumentPreview() {
            document.querySelector('[data-preview-school-name]').textContent = textValue('school_name') || 'Название школы';
            var phones = [textValue('phone_1'), textValue('phone_2')].filter(Boolean).join(' / ');
            document.querySelector('[data-preview-phones]').textContent = phones ? 'Тел.: ' + phones : 'Телефон не указан';
            document.querySelector('[data-preview-email]').textContent = textValue('email') ? 'Email: ' + textValue('email') : 'Электронная почта не указана';
            document.querySelector('[data-preview-header]').style.borderTopColor = textValue('header_color');
            document.querySelector('[data-preview-footer]').style.borderBottomColor = textValue('footer_color');

            var printDateEnabled = document.querySelector('[name="print_date_enabled"][type="checkbox"]')?.checked;
            var pageNumbersEnabled = document.querySelector('[name="page_numbers_enabled"][type="checkbox"]')?.checked;
            document.querySelector('[data-preview-print-date]').classList.toggle('d-none', !printDateEnabled);
            document.querySelector('[data-preview-page-numbers]').classList.toggle('d-none', !pageNumbersEnabled);
        }

        ['school_name', 'phone_1', 'phone_2', 'email', 'header_color', 'footer_color', 'print_date_enabled', 'page_numbers_enabled'].forEach(function (name) {
            document.querySelector('[name="' + name + '"][type="checkbox"]')?.addEventListener('change', refreshDocumentPreview);
            document.querySelector('[name="' + name + '"]:not([type="checkbox"])')?.addEventListener('input', refreshDocumentPreview);
        });
    })();
</script>
@endpush
