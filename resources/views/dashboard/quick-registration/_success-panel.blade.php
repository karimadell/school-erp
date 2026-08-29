@php
    $invoice = $registrationSuccess['invoice'];
    $student = $registrationSuccess['student'];
    $payment = $registrationSuccess['payment'];
    $paymentMethodLabel = match ($invoice->payment_method) {
        'cash' => 'Наличные', 'card' => 'Банковская карта', 'bank' => 'Банковский перевод',
        'transfer' => 'Перевод', 'instapay' => 'InstaPay', default => 'Без оплаты',
    };
    // Phase 1 — Quick Registration document semantics: the header must
    // never claim payment was confirmed unless it actually was. Keyed
    // directly on $invoice->status (unpaid/partial/paid), which is always
    // one of those three immediately after issuance — cancellation only
    // ever happens as a separate, later action, never within this
    // request. bg/border class follows the same status→color convention
    // as the "Счета" list badges (unpaid=warning, partial=info, paid=success).
    $successState = match ($invoice->status) {
        'paid' => ['class' => 'success', 'key' => 'paid'],
        'partial' => ['class' => 'info', 'key' => 'partial'],
        default => ['class' => 'warning', 'key' => 'unpaid'],
    };
@endphp
<div class="card shadow-sm border-{{ $successState['class'] }} mb-4">
    <div class="card-header bg-{{ $successState['class'] }} {{ $successState['class'] === 'warning' ? 'text-dark' : 'text-white' }} fw-bold">{{ __('quick_registration.success_header.'.$successState['key']) }}</div>
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
