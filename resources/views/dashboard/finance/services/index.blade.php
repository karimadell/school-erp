@extends('layouts.dashboard')
@section('content')
@php
    $categoryLabels = ['registration'=>'Регистрационный взнос','tuition'=>'Обучение','tuition_regular'=>'Обычное обучение','tuition_family'=>'Семейное обучение','tuition_external'=>'Экстернат','transport'=>'Транспорт','food'=>'Питание','uniform'=>'Школьная форма','books'=>'Книги','extra_classes'=>'Дополнительные занятия','activity'=>'Мероприятия','other'=>'Дополнительные услуги'];
    $hasFilters = request()->filled('search') || request()->filled('category') || request()->filled('status');
@endphp

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h3 mb-1">Услуги и сборы</h2>
            <p class="text-muted mb-0">Список того, за что школа может выставить счёт. Цены и история их изменений хранятся отдельно.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('dashboard.finance.services.create') }}">Добавить услугу</a>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted">Поиск по названию</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Поиск по названию...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Вид услуги</label>
                    <select name="category" class="form-select">
                        <option value="">Все виды</option>
                        @foreach($categoryLabels as $value => $label)
                            <option value="{{ $value }}" @selected(request('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Статус</label>
                    <select name="status" class="form-select">
                        <option value="" @selected(request('status') === null || request('status') === '')>Все</option>
                        <option value="active" @selected(request('status') === 'active')>Активные</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Неактивные</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill">Найти</button>
                    <a href="{{ route('dashboard.finance.services.index') }}" class="btn btn-outline-secondary flex-fill">Сбросить</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Услуга</th>
                        <th>Статус</th>
                        <th>Тарифов</th>
                        <th>Цена</th>
                        <th>Последнее изменение</th>
                        <th class="text-end pe-3">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($services as $fee)
                        @php
                            $current = $fee->prices->first(fn ($p) => $p->status() === 'current');
                            $future = $fee->prices->filter(fn ($p) => $p->status() === 'future')->sortBy('start_date')->first();
                        @endphp
                        <tr>
                            <td style="max-width: 320px;">
                                <div class="fw-semibold text-truncate" title="{{ $fee->name_ru }}">{{ $fee->name_ru }}</div>
                                <div class="text-muted small">{{ $categoryLabels[$fee->category] ?? $fee->category }}</div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $fee->is_active ? 'success' : 'secondary' }}">{{ $fee->is_active ? 'Активен' : 'Неактивен' }}</span>
                            </td>
                            <td>{{ $fee->prices_count }}</td>
                            <td>
                                <div class="fw-semibold">{{ $current ? number_format($current->amount, 2).' EGP' : 'Не задана' }}</div>
                                @if($future)
                                    <div class="text-muted small">с {{ $future->start_date->format('d.m.Y') }}: {{ number_format($future->amount, 2) }} EGP</div>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $fee->prices->max('created_at')?->format('d.m.Y') ?? '—' }}</td>
                            <td class="text-end pe-3">
                                <a href="{{ route('dashboard.finance.services.show', $fee) }}" class="btn btn-sm btn-outline-primary">Открыть</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                @if($hasFilters)
                                    По вашему запросу ничего не найдено. Попробуйте изменить условия поиска.
                                @else
                                    Услуги пока не созданы.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $services->links() }}
    </div>

</div>
@endsection
