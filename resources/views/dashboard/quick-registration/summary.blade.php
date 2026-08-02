@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="alert alert-success">Ученик создан. Предварительная регистрация завершена.</div>
    <div class="card mb-4"><div class="card-body">
        <h2>{{ $invoice->student->full_name }}</h2>
        <span class="badge bg-warning text-dark">Личное дело не завершено</span>
        <div class="mt-3">Заполнено: {{ $invoice->student->profile_completion_percentage }}%</div>
        <div class="progress"><div class="progress-bar" style="width: {{ $invoice->student->profile_completion_percentage }}%"></div></div>
    </div></div>
    <div class="card mb-4"><div class="card-header">Счёт {{ $invoice->invoice_number }}</div><div class="card-body">
        <table class="table"><thead><tr><th>Услуга</th><th>Стоимость</th><th>Оплачено</th><th>Остаток</th></tr></thead><tbody>
        @foreach($invoice->items as $item)<tr><td>{{ $item->description }}</td><td>{{ $item->amount }} EGP</td><td>{{ $item->paid_amount }} EGP</td><td>{{ $item->remaining_amount }} EGP</td></tr>@endforeach
        </tbody></table>
        <p><strong>Итого:</strong> {{ $invoice->total_amount }} EGP · <strong>Оплачено:</strong> {{ $invoice->paid_amount }} EGP · <strong>Остаток:</strong> {{ $invoice->remaining_amount }} EGP</p>
    </div></div>
    <a href="{{ route('dashboard.students.edit', $invoice->student) }}" class="btn btn-primary">Продолжить оформление личного дела</a>
</div>
@endsection
