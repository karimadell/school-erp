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
    <a href="{{ route('dashboard.students.complete-registration.edit', $invoice->student) }}" class="btn btn-primary">Завершить регистрацию</a>
</div>
@endsection
