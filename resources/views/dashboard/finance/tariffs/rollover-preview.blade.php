@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <h2>3. Предварительный просмотр</h2>
    <p>{{ $preview['source_year']->name }} → {{ $preview['target_year']->name }}</p>
    <div class="row g-3 mb-4">
        @foreach(['services_found'=>'Найдено услуг','tariffs_found'=>'Найдено тарифов','existing_target'=>'Тарифов уже в целевом году','new_tariffs'=>'Будет создано новых тарифов','skipped'=>'Будет пропущено'] as $key=>$label)
            <div class="col-md"><div class="card card-body h-100"><span class="text-muted">{{ $label }}</span><strong class="fs-3">{{ $preview[$key] }}</strong></div></div>
        @endforeach
    </div>
    <div class="alert alert-warning"><strong>4. Подтверждение</strong><br>Старые тарифы не изменяются.<br>Будут созданы только новые записи для выбранного учебного года.</div>
    <form method="POST" action="{{ route('dashboard.finance.tariffs.rollover.store') }}">
        @csrf
        <input type="hidden" name="source_academic_year_id" value="{{ $preview['source_year']->id }}">
        <input type="hidden" name="target_academic_year_id" value="{{ $preview['target_year']->id }}">
        <input type="hidden" name="confirmed" value="1">
        <button class="btn btn-primary" @disabled($preview['new_tariffs'] === 0)>Скопировать тарифы</button>
        <a href="{{ route('dashboard.finance.tariffs.rollover.create') }}" class="btn btn-outline-secondary">Назад</a>
    </form>
</div>
@endsection
