@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <h2>Скопировать тарифы</h2>
    <p class="text-muted">Шаги 1–2 из 4: выберите исходный и целевой учебные годы.</p>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('dashboard.finance.tariffs.rollover.preview') }}" class="card card-body">
        @csrf
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">1. Исходный учебный год</label><select name="source_academic_year_id" class="form-select" required><option value="">Выберите год</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('source_academic_year_id')==$year->id)>{{ $year->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">2. Целевой учебный год</label><select name="target_academic_year_id" class="form-select" required><option value="">Выберите год</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('target_academic_year_id')==$year->id)>{{ $year->name }}</option>@endforeach</select></div>
        </div>
        <button class="btn btn-primary mt-4">Перейти к предварительному просмотру</button>
    </form>
</div>
@endsection
