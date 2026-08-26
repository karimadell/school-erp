@extends('layouts.dashboard')

@section('content')
@php $enrollment = $invoice->student->enrollments->sortByDesc('id')->first(); @endphp
<div class="container-fluid py-4">
    <div class="alert alert-success">Ученик создан. Предварительная регистрация завершена.</div>
    <div class="card mb-4"><div class="card-body">
        <h2>{{ $invoice->student->full_name }}</h2>
        <span class="badge bg-warning text-dark">Предварительная регистрация</span>
        <p class="alert alert-warning mt-3 mb-2">Данные ученика заполнены не полностью.</p>
        <div class="mt-3">Заполнено: {{ $invoice->student->profile_completion_percentage }}%</div>
        <div class="progress"><div class="progress-bar" style="width: {{ $invoice->student->profile_completion_percentage }}%"></div></div>
    </div></div>
    <div class="card mb-4"><div class="card-header">Сведения о регистрации</div><div class="card-body">
        <p><strong>Учебный год:</strong> {{ $invoice->academicYear?->name }}</p>
        <p><strong>Ступень / класс:</strong> {{ $enrollment?->stage?->name }} / {{ $enrollment?->schoolClass?->name }}</p>
        <p><strong>Форма обучения:</strong> {{ $enrollment?->enrollmentMode?->name_ru }}</p>
    </div></div>
    <div class="card mb-4"><div class="card-header">Номер счёта: {{ $invoice->invoice_number }}</div><div class="card-body">
        <table class="table"><thead><tr><th>Услуга</th><th>Стоимость</th><th>Оплачено</th><th>Остаток</th></tr></thead><tbody>
        @foreach($invoice->items as $item)<tr><td>{{ $item->description }}</td><td>{{ $item->amount }} EGP</td><td>{{ $item->paid_amount }} EGP</td><td>{{ $item->remaining_amount }} EGP</td></tr>@endforeach
        </tbody></table>
        <p><strong>Итого:</strong> {{ $invoice->total_amount }} EGP · <strong>Оплачено:</strong> {{ $invoice->paid_amount }} EGP · <strong>Остаток:</strong> {{ $invoice->remaining_amount }} EGP</p>
        <p><strong>Способ оплаты:</strong> {{ match($invoice->payment_method) { 'cash' => 'Наличные', 'card' => 'Карта', 'bank' => 'Банк', 'transfer' => 'Перевод', default => 'Без оплаты' } }}</p>
        <p><strong>Касса:</strong> {{ $invoice->cashAccount?->name ?? 'Без оплаты' }}</p>
    </div></div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('dashboard.students.complete-registration.edit', $invoice->student) }}" class="btn btn-primary">Завершить регистрацию</a>
        @if($invoice->payments->isNotEmpty())
            <a href="{{ route('dashboard.payments.receipt', $invoice->payments->sortByDesc('id')->first()) }}" class="btn btn-success">Открыть квитанцию</a>
        @endif
        <a href="{{ route('dashboard.invoices.print', $invoice) }}" class="btn btn-outline-secondary" target="_blank">Печать счёта</a>
        <a href="{{ route('dashboard.invoices.pdf', $invoice) }}" class="btn btn-outline-danger">PDF</a>
        <a href="{{ route('dashboard.invoices.show', $invoice) }}" class="btn btn-outline-secondary">Просмотр счёта</a>
        <a href="{{ route('dashboard.students.show', $invoice->student) }}" class="btn btn-outline-primary">Профиль ученика</a>
        @if(bccomp((string) $invoice->remaining_amount, '0.00', 2) > 0)
            <a href="{{ route('dashboard.invoices.payments.create', $invoice) }}" class="btn btn-outline-success">Следующий платёж</a>
        @else
            <a href="{{ route('dashboard.students.finance', $invoice->student) }}" class="btn btn-outline-success">Добавить услугу / новый платёж</a>
        @endif
    </div>
</div>
@endsection
