@extends('layouts.dashboard')

@section('content')
<div class="container">

    <h3 class="mb-4">Добавить услугу</h3>

    <form method="POST" action="{{ route('dashboard.fees.store') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Название услуги</label>
            <input type="text"
                   name="name_ru"
                   class="form-control"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Категория</label>
            <input type="text"
                   name="category"
                   class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Класс (Grade)</label>

            <select name="grade_id" class="form-select">
                <option value="">— Для всех классов —</option>

                @foreach($grades as $grade)
                    <option value="{{ $grade->id }}">
                        {{ $grade->name }}
                    </option>
                @endforeach

            </select>

            <small class="text-muted">
                Если услуга относится к определённому классу (например обучение), выберите класс.
            </small>
        </div>

        <div class="mb-3">
            <label class="form-label">Период оплаты</label>
            <select name="payment_period" class="form-select">
                <option value="">—</option>
                <option value="once">Единовременно</option>
                <option value="monthly">Ежемесячно</option>
                <option value="yearly">Ежегодно</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Сумма</label>
            <input type="number"
                   name="amount"
                   step="0.01"
                   min="0"
                   class="form-control"
                   required>
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input"
                   type="checkbox"
                   name="is_active"
                   value="1"
                   checked>
            <label class="form-check-label">
                Активная услуга
            </label>
        </div>

        <button class="btn btn-success">
            Сохранить
        </button>

        <a href="{{ route('dashboard.fees.index') }}"
           class="btn btn-secondary">
            Отмена
        </a>
    </form>

</div>
@endsection