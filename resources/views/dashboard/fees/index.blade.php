@extends('layouts.dashboard')

@section('content')

@php
    $categories = [
        'tuition' => 'Обучение',
        'tuition_regular' => 'Очная форма обучения',
        'tuition_family' => 'Семейная форма обучения',
        'tuition_external' => 'Экстернат',
        'registration' => 'Регистрационный взнос',
        'extra_classes' => 'Допы',
        'uniform' => 'Школьная форма',
        'transport' => 'Транспорт',
        'food' => 'Питание',
        'other' => 'Другое',
    ];

    $periods = [
        'once' => 'Разово',
        'one_time' => 'Разово',
        'daily' => 'Ежедневно',
        'weekly' => 'Еженедельно',
        'monthly' => 'Ежемесячно',
        'quarterly' => 'Каждые 3 месяца',
        'yearly' => 'Ежегодно',
        'package' => 'Пакет',
    ];
@endphp

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0">Услуги</h3>

        <a href="{{ route('dashboard.fees.create') }}" class="btn btn-primary">
            + Добавить услугу
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('dashboard.fees.index') }}">
                <div class="row g-3">

                    <div class="col-md-4">
                        <input type="text"
                               name="search"
                               class="form-control"
                               value="{{ request('search') }}"
                               placeholder="Поиск...">
                    </div>

                    <div class="col-md-4">
                        <select name="category" class="form-select">
                            <option value="">— Все категории —</option>

                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}" @selected(request('category') === $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <button class="btn btn-primary w-100">
                            Фильтр
                        </button>
                    </div>

                    <div class="col-md-2">
                        <a href="{{ route('dashboard.fees.index') }}" class="btn btn-outline-secondary w-100">
                            Сброс
                        </a>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Название</th>
                        <th>Категория</th>
                        <th>Класс</th>
                        <th>Период</th>
                        <th>Сумма</th>
                        <th>Статус</th>
                        <th style="width:280px;">Действия</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($fees as $fee)
                        <tr>
                            <td>{{ $fee->id }}</td>

                            <td>
                                <strong>{{ $fee->name_ru ?? '—' }}</strong>

                                @if(!empty($fee->description))
                                    <br>
                                    <small class="text-muted">{{ $fee->description }}</small>
                                @endif
                            </td>

                            <td>
                                {{ $categories[$fee->category] ?? $fee->category ?? '—' }}
                            </td>

                            <td>
                                {{ $fee->grade?->name_ru ?? $fee->grade?->name ?? '—' }}
                            </td>

                            <td>
                                {{ $periods[$fee->payment_period] ?? $fee->payment_period ?? '—' }}
                            </td>

                            <td>
                                {{ number_format($fee->amount ?? $fee->base_price ?? 0, 2) }}
                            </td>

                            <td>
                                @if($fee->is_active)
                                    <span class="badge bg-success">Активна</span>
                                @else
                                    <span class="badge bg-secondary">Отключена</span>
                                @endif
                            </td>

                            <td>
                                <div class="d-flex gap-2">

                                    <a href="{{ route('dashboard.fees.edit', $fee) }}"
                                       class="btn btn-sm btn-warning">
                                        Редактировать
                                    </a>

                                    <form method="POST" action="{{ route('dashboard.fees.toggle', $fee) }}">
                                        @csrf
                                        @method('PATCH')

                                        <button class="btn btn-sm {{ $fee->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                            {{ $fee->is_active ? 'Отключить' : 'Включить' }}
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action="{{ route('dashboard.fees.destroy', $fee) }}"
                                          onsubmit="return confirm('Удалить услугу?')">
                                        @csrf
                                        @method('DELETE')

                                        <button class="btn btn-sm btn-outline-danger">
                                            Удалить
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                Нет данных
                            </td>
                        </tr>
                    @endforelse
                </tbody>

            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $fees->appends(request()->query())->links() }}
    </div>

</div>

@endsection