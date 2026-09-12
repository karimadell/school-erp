@extends('layouts.dashboard')
@section('content')
<div class="container py-4"><h1 class="h3 mb-4">Принять оплату</h1>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Счёт {{ $invoice->display_number }}</h2><div>{{ $invoice->student?->full_name }}</div><div class="row g-2 mt-3"><div class="col-md-4">Итого: <strong>{{ $invoice->total_amount }} EGP</strong></div><div class="col-md-4">Оплачено: <strong>{{ $invoice->paid_amount }} EGP</strong></div><div class="col-md-4">Остаток: <strong>{{ $invoice->remaining_amount }} EGP</strong></div></div></div></div>

{{--
    Student Payment Allocation UX corrective — Sections B/C/D.

    No controller change: createPayment() already computes exactly the two
    facts this view needs — $allocationClean (bool) and $remainingByItem
    (Collection<invoice_item_id, string>, empty when not clean) — via
    InvoicePaymentService::isAllocationClean()/remainingAllocatableByItem().
    This view only decides which of three presentations to render from
    those same two facts plus $invoice->items->count():

      1. Single item                       -> simple, no allocation UI.
      2. Multi-item, allocation-clean       -> editable per-item "pay now"
                                               inputs, backed by the existing
                                               allocations[item_id] contract.
      3. Multi-item, allocation-ambiguous   -> read-only item breakdown +
                                               warning; payment stays
                                               invoice-level exactly as the
                                               backend already enforces.

    The backend remains the sole source of truth in every case: this view
    never invents a split, and the JS below only mirrors the sum of what the
    cashier typed into the "Сумма, EGP" field for convenience — it does not
    replace InvoicePaymentService::validateAllocations()'s own authoritative
    checks (sum-must-match, per-item cap, clean/ambiguous gate).
--}}
@php
    $items = $invoice->items;
    $isMultiItem = $items->count() > 1;
    $isAmbiguous = $isMultiItem && ! ($allocationClean ?? false);
@endphp

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h2 class="h6 mb-3">Состав счёта</h2>
    @if($allocationClean ?? false)
        {{-- Multi-item, allocation-clean: editable per-item split. --}}
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Услуга</th><th class="text-end">Сумма строки</th><th class="text-end">Уже оплачено</th><th class="text-end">Остаток</th><th class="text-end" style="min-width:140px">Оплатить сейчас</th></tr></thead>
            <tbody>
            @foreach($items as $item)
                @php $remaining = (string) $remainingByItem->get($item->id, '0.00'); @endphp
                <tr>
                    <td>
                        {{ $item->fee?->name_ru ?? $item->description }}
                        @if($period = \App\Support\InvoiceItemPeriodLabel::forItem($item))
                            <div class="small text-muted">{{ $period }}</div>
                        @endif
                    </td>
                    <td class="text-end">{{ $item->amount }} EGP</td>
                    <td class="text-end">{{ bcsub((string) $item->amount, $remaining, 2) }} EGP</td>
                    <td class="text-end fw-semibold">{{ $remaining }} EGP</td>
                    <td class="text-end">
                        <input type="number" step="0.01" min="0" max="{{ $remaining }}"
                               name="allocations[{{ $item->id }}]"
                               value="{{ old('allocations.'.$item->id) }}"
                               class="form-control form-control-sm allocation-input text-end"
                               data-remaining="{{ $remaining }}"
                               form="payment-form">
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td class="fw-semibold">Итого к оплате по выбранным услугам</td>
                    <td></td><td></td><td></td>
                    <td class="text-end fw-bold"><span id="allocation-total">0.00</span> EGP</td>
                </tr>
            </tfoot>
        </table></div>
        <div class="small text-muted mt-2">Сумма распределения по услугам должна совпадать с суммой платежа. Оставьте поле пустым или укажите 0, чтобы не оплачивать эту услугу сейчас.</div>
    @elseif($isAmbiguous)
        {{-- Multi-item, allocation-ambiguous: informational breakdown only, no selection UI. --}}
        <div class="table-responsive"><table class="table table-sm mb-2">
            <thead><tr><th>Услуга</th><th class="text-end">Сумма строки</th></tr></thead>
            <tbody>
            @foreach($items as $item)
                <tr>
                    <td>
                        {{ $item->fee?->name_ru ?? $item->description }}
                        @if($period = \App\Support\InvoiceItemPeriodLabel::forItem($item))
                            <div class="small text-muted">{{ $period }}</div>
                        @endif
                    </td>
                    <td class="text-end">{{ $item->amount }} EGP</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="alert alert-warning mb-0 py-2 px-3 small">По этому счёту есть исторические платежи без полного распределения по услугам. Выбор конкретной услуги недоступен; платёж будет учтён на уровне счёта.</div>
    @else
        {{-- Single item: simple, no allocation UI. --}}
        @foreach($items as $item)
            <div class="d-flex justify-content-between">
                <span>
                    {{ $item->fee?->name_ru ?? $item->description }}
                    @if($period = \App\Support\InvoiceItemPeriodLabel::forItem($item))
                        <span class="small text-muted"> — {{ $period }}</span>
                    @endif
                </span>
                <span class="fw-semibold">{{ $item->amount }} EGP</span>
            </div>
        @endforeach
    @endif
</div></div>

<form id="payment-form" method="POST" action="{{ route('dashboard.invoices.payments.store',$invoice) }}" class="card border-0 shadow-sm"><div class="card-body row g-3">@csrf<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
@if($invoice->installments->isNotEmpty())<div class="col-12"><label class="form-label">Этап рассрочки</label><select name="invoice_installment_id" id="installment" class="form-select" required>@foreach($invoice->installments as $installment)<option value="{{ $installment->id }}" data-remaining="{{ $installment->remaining_amount }}" @selected(old('invoice_installment_id')==$installment->id)>{{ $installment->name_ru }} · до {{ $installment->due_date->format('d.m.Y') }} · остаток {{ $installment->remaining_amount }} EGP</option>@endforeach</select></div>@endif
<div class="col-md-4"><label class="form-label">Сумма, EGP</label><input id="payment-amount" type="number" step="0.01" min="0.01" max="{{ $invoice->installments->first()?->remaining_amount ?? $invoice->remaining_amount }}" name="amount" value="{{ old('amount',$invoice->installments->first()?->remaining_amount ?? $invoice->remaining_amount) }}" class="form-control" required></div>
<div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" id="payment-method" class="form-select" required><option value="cash">Наличные</option><option value="card">Банковская карта</option><option value="bank">Банковский перевод</option><option value="instapay">InstaPay</option></select></div>
<div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" id="cash-account" class="form-select" required><option value="">Выберите кассу</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select><div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div></div>
<div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control">{{ old('notes') }}</textarea></div><div class="col-12"><div class="small text-muted mb-3">Дата и время платежа фиксируются системой при проведении.</div><button class="btn btn-success">Принять оплату</button></div></div></form></div>
@if($invoice->installments->isNotEmpty())<script>document.getElementById('installment').addEventListener('change',event=>{const amount=document.getElementById('payment-amount'),remaining=event.target.selectedOptions[0].dataset.remaining;amount.max=remaining;amount.value=remaining;});</script>@endif
<script>
(function () {
    const method = document.getElementById('payment-method');
    const account = document.getElementById('cash-account');
    const hint = document.getElementById('cash-account-auto-hint');
    function sync() {
        const canonical = ['cash', 'bank', 'instapay'].includes(method.value);
        account.disabled = canonical;
        account.required = !canonical;
        hint.classList.toggle('d-none', !canonical);
    }
    method.addEventListener('change', sync);
    sync();
})();
</script>
@if($allocationClean ?? false)
<script>
(function () {
    // Display-only convenience: mirrors the sum of the per-item "Оплатить
    // сейчас" inputs into the invoice-level amount field and a running
    // total, so the cashier does not have to add the lines up by hand.
    // Purely presentational — the backend (InvoicePaymentService::
    // validateAllocations()) is still the sole authority on whether the
    // submitted allocations actually sum to the submitted amount.
    const inputs = Array.from(document.querySelectorAll('.allocation-input'));
    const totalLabel = document.getElementById('allocation-total');
    const amountField = document.getElementById('payment-amount');
    let amountTouchedByUser = false;
    amountField.addEventListener('input', () => { amountTouchedByUser = true; });

    function recalculate() {
        let total = 0;
        inputs.forEach(input => {
            const value = parseFloat(input.value);
            total += Number.isFinite(value) ? value : 0;
        });
        totalLabel.textContent = total.toFixed(2);
        if (!amountTouchedByUser) {
            amountField.value = total > 0 ? total.toFixed(2) : amountField.value;
        }
    }

    inputs.forEach(input => input.addEventListener('input', recalculate));
    recalculate();
})();
</script>
@endif
@endsection
