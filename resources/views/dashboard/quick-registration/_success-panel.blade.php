@php
    $invoice = $registrationSuccess['invoice'];
    $student = $registrationSuccess['student'];
    $payment = $registrationSuccess['payment'];
    $paymentMethodLabel = match ($invoice->payment_method) {
        'cash' => 'Наличные', 'card' => 'Банковская карта', 'bank' => 'Банковский перевод',
        'transfer' => 'Перевод', 'instapay' => 'InstaPay', default => 'Без оплаты',
    };
@endphp
<div class="card shadow-sm border-success mb-4">
    <div class="card-header bg-success text-white fw-bold">Регистрация и оплата подтверждены</div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="text-muted small">Ученик</div><div class="fw-bold">{{ $student->full_name }} (ID {{ $student->id }})</div></div>
            <div class="col-md-4"><div class="text-muted small">Номер счёта</div><div class="fw-bold">{{ $invoice->invoice_number }}</div></div>
            <div class="col-md-4"><div class="text-muted small">Квитанция №</div><div class="fw-bold">{{ $payment?->payment_number ?? '—' }}</div></div>
            <div class="col-md-3"><div class="text-muted small">Итого по счёту</div><div class="fw-bold">{{ $invoice->total_amount }} EGP</div></div>
            <div class="col-md-3"><div class="text-muted small">Оплачено сейчас</div><div class="fw-bold">{{ $payment?->amount ?? '0.00' }} EGP</div></div>
            <div class="col-md-3"><div class="text-muted small">Остаток</div><div class="fw-bold">{{ $invoice->remaining_amount }} EGP</div></div>
            <div class="col-md-3"><div class="text-muted small">Способ оплаты</div><div class="fw-bold">{{ $paymentMethodLabel }}</div></div>
            <div class="col-md-4"><div class="text-muted small">Касса / счёт</div><div class="fw-bold">{{ $invoice->cashAccount?->name ?? '—' }}</div></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($payment)
                <a href="{{ route('dashboard.payments.receipt', $payment) }}" target="_blank" class="btn btn-success">Печать квитанции</a>
            @endif
            <a href="{{ route('dashboard.invoices.print', $invoice) }}" target="_blank" class="btn btn-outline-secondary">Печать счёта</a>
            <a href="{{ route('dashboard.quick-registration.create') }}" class="btn btn-primary">Новая регистрация</a>
        </div>
    </div>
</div>
