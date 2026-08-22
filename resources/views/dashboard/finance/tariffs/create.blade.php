@extends('layouts.dashboard')
@section('content')<div class="container py-4"><h2>Новый тариф</h2><div class="alert alert-info">Изменение цены создаёт новую версию тарифа и не меняет старые счета.<br>Тариф выбирается по дате регистрации или выставления счета.<br>Для одного учебного года можно создать льготный и основной тарифы с разными периодами действия.</div>@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('dashboard.finance.tariffs.store') }}" class="card card-body">@csrf<div class="row g-3"><div class="col-md-6"><label class="form-label">Услуга</label><select name="fee_id" id="fee-id" class="form-select" required>@foreach($services as $s)<option value="{{ $s->id }}" data-category="{{ $s->category }}" @selected(old('fee_id',$selectedFeeId)==$s->id)>{{ $s->name_ru }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">Учебный год</label><select name="academic_year_id" class="form-select" required>@foreach($years as $y)<option value="{{ $y->id }}" @selected(old('academic_year_id')==$y->id)>{{ $y->name }}</option>@endforeach</select></div><div class="col-md-4"><label class="form-label">Цена, EGP</label><input name="amount" value="{{ old('amount') }}" inputmode="decimal" class="form-control" required></div><div class="col-md-4"><label class="form-label">Действует с</label><input type="date" name="start_date" value="{{ old('start_date') }}" class="form-control" required></div><div class="col-md-4"><label class="form-label">Действует до</label><input type="date" name="end_date" value="{{ old('end_date') }}" class="form-control"></div><div class="col-12"><label class="form-label">Причина изменения</label><input name="change_reason" value="{{ old('change_reason') }}" class="form-control"></div><div class="col-12 form-check ms-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="active" @checked(old('is_active',true))><label for="active">Активен</label></div></div>
<details class="mt-4"><summary class="fw-bold">{{ __('finance_uat.grade_and_payment_period') }}</summary><div class="row g-3 mt-1"><div class="col-md-4" data-tuition-field><label>{{ __('finance_uat.exact_grade') }}</label><select name="grade_id" class="form-select"><option value="">{{ __('finance_uat.all_grades') }}</option>@foreach($grades as $g)<option value="{{ $g->id }}" @selected(old('grade_id')==$g->id)>{{ $g->name }}</option>@endforeach</select></div><div class="col-md-4" data-tuition-field><label>{{ __('finance_uat.grade_group') }}</label><select name="grade_group" class="form-select"><option value="">{{ __('finance_uat.all_grade_groups') }}</option>@foreach(\App\Models\FeePrice::GRADE_GROUPS as $group)<option value="{{ $group }}" @selected(old('grade_group')===$group)>{{ $group }}</option>@endforeach</select><div class="form-text">{{ __('finance_uat.grade_group_help') }}</div></div><div class="col-md-4"><label>{{ __('finance_uat.payment_period') }}</label><select name="payment_period" class="form-select"><option value="">{{ __('finance_uat.not_set') }}</option>@foreach(['once'=>'Разово','daily'=>'Ежедневно','monthly'=>'Ежемесячно','quarterly'=>'Ежеквартально','term'=>'За семестр','yearly'=>'За год','package'=>'Пакет'] as $value=>$label)<option value="{{ $value }}" @selected(old('payment_period')===$value)>{{ $label }}</option>@endforeach</select></div></div></details>
<details class="mt-3" id="special-parameters" hidden><summary class="fw-bold">{{ __('finance_uat.special_service_parameters') }}</summary><p class="text-muted small mt-2 mb-2">{{ __('finance_uat.special_service_help') }}</p><div class="row g-3"><div data-for-category="transport"><input type="hidden" name="option_type" value="zone" disabled></div><div class="col-md-6" data-for-category="transport"><label>{{ __('finance_uat.transport_zone') }}</label><input name="option_value" value="{{ old('option_value') }}" class="form-control"></div><div class="col-md-6" data-for-category="food"><label>{{ __('finance_uat.meal_item') }}</label><input name="item" value="{{ old('item') }}" class="form-control"></div><div class="col-md-6" data-for-category="uniform"><label>{{ __('finance_uat.uniform_item') }}</label><input name="item" value="{{ old('item') }}" class="form-control"></div><div class="col-md-6" data-for-category="uniform"><label>{{ __('finance_uat.uniform_size') }}</label><input name="size" value="{{ old('size') }}" class="form-control"></div></div></details><button class="btn btn-primary mt-4">Создать тариф</button></form></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
	    const service = document.getElementById('fee-id');
	    const section = document.getElementById('special-parameters');
    const refresh = () => {
	        const category = service.options[service.selectedIndex]?.dataset.category || '';
	        const tuition = ['tuition', 'tuition_regular', 'tuition_family', 'tuition_external'].includes(category);
		        document.querySelectorAll('[data-tuition-field]').forEach((field) => {
		            field.hidden = !tuition;
		            field.querySelectorAll('select').forEach((input) => input.disabled = !tuition);
		        });
        let visible = false;
        section.querySelectorAll('[data-for-category]').forEach((field) => {
            const show = field.dataset.forCategory.split(' ').includes(category);
            field.hidden = !show;
            field.querySelectorAll('input').forEach((input) => input.disabled = !show);
            visible ||= show;
        });
        section.hidden = !visible;
        if (!visible) section.open = false;
    };
    service.addEventListener('change', refresh);
    refresh();
});
</script>
@endsection
