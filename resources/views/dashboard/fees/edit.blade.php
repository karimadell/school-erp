@extends('layouts.dashboard')

@section('content')
<div class="container">

    <h3 class="mb-4">Редактировать услугу</h3>

    <form method="POST"
          action="{{ route('dashboard.fees.update', $fee) }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label class="form-label">Название услуги</label>
            <input type="text"
                   name="name_ru"
                   class="form-control"
                   value="{{ $fee->name_ru }}"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Категория</label>
            <input type="text"
                   name="category"
                   class="form-control"
                   value="{{ $fee->category }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Период оплаты</label>
            <select name="payment_period" class="form-select">
                <option value="">—</option>
                <option value="once" @selected($fee->payment_period === 'once')>
                    Единовременно
                </option>
                <option value="monthly" @selected($fee->payment_period === 'monthly')>
                    Ежемесячно
                </option>
                <option value="yearly" @selected($fee->payment_period === 'yearly')>
                    Ежегодно
                </option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Сумма</label>
            <input type="number"
                   name="amount"
                   step="0.01"
                   min="0"
                   class="form-control"
                   value="{{ $fee->amount }}"
                   required>
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input"
                   type="checkbox"
                   name="is_active"
                   value="1"
                   @checked($fee->is_active)>
            <label class="form-check-label">
                Активная услуга
            </label>
        </div>

        <button class="btn btn-success">
            Обновить
        </button>

        <a href="{{ route('dashboard.fees.index') }}"
           class="btn btn-secondary">
            Назад
        </a>
    </form>

</div>
@endsection