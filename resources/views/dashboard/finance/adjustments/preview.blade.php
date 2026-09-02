@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <h1 class="h3">Предпросмотр корректировки тарифа</h1>
    <p class="text-muted">Предпросмотр не создаёт долг. Каждая корректировка проводится только отдельным подтверждением бухгалтера.</p>
    @forelse($previews as $preview)<div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Ученик</dt><dd class="col-sm-8">{{ $preview['coverage']->student->full_name }}</dd>
            <dt class="col-sm-4">Услуга</dt><dd class="col-sm-8">{{ $preview['coverage']->fee->name_ru }}</dd>
            <dt class="col-sm-4">Контекст</dt><dd class="col-sm-8">{{ collect([$preview['coverage']->option_value, $preview['coverage']->payment_period])->filter()->implode(' · ') ?: '—' }}</dd>
            <dt class="col-sm-4">Покрытие</dt><dd class="col-sm-8">{{ $preview['coverage']->coverage_start->format('d.m.Y') }} — {{ $preview['coverage']->coverage_end->format('d.m.Y') }}</dd>
            <dt class="col-sm-4">Предыдущий тариф</dt><dd class="col-sm-8">{{ $preview['previous_unit_price'] }} EGP</dd>
            <dt class="col-sm-4">Новый тариф</dt><dd class="col-sm-8">{{ $preview['new_unit_price'] }} EGP</dd>
            <dt class="col-sm-4">Дата вступления в силу</dt><dd class="col-sm-8">{{ \Illuminate\Support\Carbon::parse($preview['new_fee_price']->start_date)->format('d.m.Y') }}</dd>
            <dt class="col-sm-4">Начисляемый период</dt><dd class="col-sm-8">{{ $preview['segment'] ? implode(' — ', $preview['segment']) : 'Нет покрытых единиц' }}</dd>
            <dt class="col-sm-4">Единиц</dt><dd class="col-sm-8">{{ $preview['units'] }}</dd>
            <dt class="col-sm-4">Разница за единицу</dt><dd class="col-sm-8">{{ $preview['difference_per_unit'] }} EGP</dd>
            <dt class="col-sm-4">Итог</dt><dd class="col-sm-8 fw-bold">{{ $preview['total_difference'] }} EGP</dd>
        </dl>
        <p class="text-muted small mt-2 mb-0">Начисляемый период всегда состоит из целых единиц покрытия (месяцев или дней) — без пропорционального пересчёта частичного периода.</p>
        @if($preview['units'] > 0 && bccomp($preview['total_difference'], '0.00', 2) !== 0) @can('approve tariff adjustments')
            <form method="POST" action="{{ route('dashboard.finance.adjustments.store') }}">@csrf
                <input type="hidden" name="service_coverage_id" value="{{ $preview['coverage']->id }}">
                <input type="hidden" name="new_fee_price_id" value="{{ $preview['new_fee_price']->id }}">
                <label class="form-label mt-3">Комментарий</label><textarea class="form-control" name="note"></textarea>
                <button class="btn btn-danger mt-3">Подтвердить и провести корректировку</button>
            </form>
        @endcan @endif
    </div></div>@empty<div class="alert alert-info">Нет покрытых учеников, для которых возникает разница.</div>@endforelse
</div>
@endsection
