@extends('layouts.dashboard')
@section('content')
<div class="container py-4"><h2>Скачать прайс-лист PDF</h2>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<form method="GET" action="{{ route('dashboard.finance.tariffs.price-list.pdf') }}" class="card card-body">
<label class="form-label">Учебный год</label><select name="academic_year_id" class="form-select mb-3" required>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('academic_year_id',$selectedYearId)==$year->id)>{{ $year->name }}</option>@endforeach</select>
<label class="form-label">Категории услуг</label><div class="row mb-3">@foreach($categoryOptions as $value=>$label)<div class="col-md-4"><label><input type="checkbox" name="categories[]" value="{{ $value }}" checked> {{ $label }}</label></div>@endforeach</div>
<label class="mb-3"><input type="checkbox" name="include_inactive" value="1"> Включить неактивные тарифы</label>
<button class="btn btn-primary">Скачать прайс-лист PDF</button></form></div>
@endsection
