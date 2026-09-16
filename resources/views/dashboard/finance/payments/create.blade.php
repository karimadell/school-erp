@extends('layouts.dashboard')
@section('content')
<div class="container py-4">

    <h1 class="h3 mb-1">Принять оплату</h1>
    <div class="text-muted mb-4">{{ $invoice->student?->full_name }} · Счёт {{ $invoice->display_number }}</div>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Начислено</div>
                <strong>{{ $invoice->total_amount }} EGP</strong>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Оплачено</div>
                <strong>{{ $invoice->paid_amount }} EGP</strong>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Задолженность</div>
                <strong class="text-danger">{{ $invoice->remaining_amount }} EGP</strong>
            </div></div>
        </div>
    </div>

    {{--
        Payment collection UX corrective.

        createPayment() still computes exactly the same three facts this
        view always has ($allocationClean, $remainingByItem,
        $remainingByItemPerInstallment) via InvoicePaymentService's own
        isAllocationClean()/remainingAllocatableByItem()/
        remainingByItemPerInstallment() — unchanged, no controller edit
        was needed for this pass. What changed is purely how this same
        data is arranged and labeled:

          - "Что оплачиваем?" is now always an itemized, per-service table
            (single item included — it used to be a bare display line),
            filtered to OUTSTANDING items; fully paid items move into a
            collapsed "Показать оплаченные услуги" section instead of
            cluttering the primary view. The ambiguous (historically
            unallocated) case is untouched in content — there is no
            reliable per-item remaining figure to itemize there, exactly
            as before — only its visual chrome matches the new page.

          - "Период оплаты" (the old bare "Этап рассрочки" dropdown) no
            longer defaults to the lowest-sequence installment. With more
            than one installment, the accountant must consciously pick
            one (a real, disabled placeholder option, native `required`);
            with exactly one, it is shown as the obvious, unambiguous
            choice (still a real, submitted field — nothing to choose
            between). No amount is ever pre-filled from "whichever
            installment happens to be first."

        The backend remains the sole source of truth in every case: this
        view never invents a split, and the JS below only mirrors what
        the accountant typed for convenience — it does not replace
        InvoicePaymentService::validateAllocations()'s own authoritative
        checks (sum-must-match, per-item cap, clean/ambiguous gate,
        per-installment cap).
    --}}
    @php
        $items = $invoice->items;
        $isMultiItem = $items->count() > 1;
        $isAmbiguous = $isMultiItem && ! ($allocationClean ?? false);
        $itemized = ! $isAmbiguous; // single item, or allocation-clean multi-item.

        // Single-item invoices never get remainingAllocatableByItem() from
        // the controller (it is only computed when allocationClean, which
        // requires >1 item) — there is nothing ambiguous to resolve for a
        // single item, so its own remaining is simply the invoice's own
        // remaining/paid figures, already fully authoritative.
        $wholeRemainingByItem = $allocationClean
            ? $remainingByItem
            : ($itemized ? collect([$items->first()?->id => (string) $invoice->remaining_amount]) : collect());
        $paidByItem = $allocationClean
            ? $items->mapWithKeys(fn ($item) => [$item->id => bcsub((string) $item->amount, $wholeRemainingByItem->get($item->id, '0.00'), 2)])
            : ($itemized ? collect([$items->first()?->id => (string) $invoice->paid_amount]) : collect());

        $outstandingItems = $itemized
            ? $items->filter(fn ($item) => bccomp($wholeRemainingByItem->get($item->id, '0.00'), '0.00', 2) > 0)
            : collect();
        $paidItems = $itemized ? $items->diff($outstandingItems) : collect();

        $installmentCount = $invoice->installments->count();
        // A period choice is only ever ambiguous with more than one
        // installment — with zero or exactly one, there is nothing for the
        // accountant to actually decide between.
        $installmentChoiceRequired = $installmentCount > 1;
        $defaultInstallmentId = $installmentCount === 1
            ? $invoice->installments->first()->id
            : (int) old('invoice_installment_id');
        $defaultInstallmentCoverage = $remainingByItemPerInstallment->get($defaultInstallmentId, collect());
        // Items with calendar coverage under ANY installment — used below
        // so a shared/no-coverage installment (e.g. Разовые услуги) never
        // silently offers a DIFFERENT installment's own service at its
        // full whole-invoice remaining. Faithfully mirrors the same
        // restriction the JS re-applies on every selection change.
        // ->values() re-indexes after unique() so a non-empty result always
        // serializes as a JSON array (sequential 0..n-1 keys), never an
        // object with gapped numeric keys.
        $itemsWithSomeCoverage = $remainingByItemPerInstallment->flatMap(fn ($perItem) => $perItem->keys())->unique()->values();
    @endphp

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h6 mb-3">Что оплачиваем?</h2>

        @if($installmentCount > 0)
            <div class="mb-3">
                <label class="form-label small text-muted" for="installment">Период оплаты</label>
                @if($installmentChoiceRequired)
                    <select name="invoice_installment_id" id="installment" class="form-select" required form="payment-form">
                        <option value="" selected disabled>— Выберите период оплаты —</option>
                        @foreach($invoice->installments as $installment)
                            <option value="{{ $installment->id }}" data-remaining="{{ $installment->remaining_amount }}" @selected(old('invoice_installment_id') == $installment->id)>{{ $installment->name_ru }} · до {{ $installment->due_date->format('d.m.Y') }} · остаток {{ $installment->remaining_amount }} EGP</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        «Разовые услуги» — разовые сборы без ежемесячного графика (регистрация, школьная форма и т.п.). «Период N» — начисление за соответствующий календарный период (обучение, транспорт и т.п.). Выберите период, чтобы указать суммы по услугам ниже.
                    </div>
                @else
                    @php $onlyInstallment = $invoice->installments->first(); @endphp
                    <input type="hidden" name="invoice_installment_id" value="{{ $onlyInstallment->id }}" form="payment-form">
                    <div class="form-control-plaintext">{{ $onlyInstallment->name_ru }} · до {{ $onlyInstallment->due_date->format('d.m.Y') }}</div>
                @endif
            </div>
        @endif

        @if($itemized)
            @if($outstandingItems->isEmpty())
                <div class="text-muted small">По этому счёту нет услуг с непогашенным остатком.</div>
            @else
                <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="allocation-table"
                    data-coverage-remaining="{{ $remainingByItemPerInstallment->toJson() }}"
                    data-whole-invoice-remaining="{{ $wholeRemainingByItem->toJson() }}"
                    data-items-with-coverage="{{ $itemsWithSomeCoverage->toJson() }}">
                    <thead><tr><th>Услуга</th><th class="text-end">Начислено</th><th class="text-end">Оплачено</th><th class="text-end">Остаток</th><th class="text-end" style="min-width:140px">Оплатить сейчас</th></tr></thead>
                    <tbody>
                    @foreach($outstandingItems as $item)
                        @php
                            $wholeInvoiceRemaining = (string) $wholeRemainingByItem->get($item->id, '0.00');
                            $installmentCap = $defaultInstallmentCoverage->get($item->id);
                            $effectiveRemaining = $installmentCap !== null
                                ? (bccomp($installmentCap, $wholeInvoiceRemaining, 2) < 0 ? $installmentCap : $wholeInvoiceRemaining)
                                : $wholeInvoiceRemaining;
                            // No period chosen yet where one is required — nothing is
                            // payable until the accountant makes that choice.
                            $awaitingInstallmentChoice = $installmentChoiceRequired && $defaultInstallmentId === 0;
                        @endphp
                        <tr>
                            <td>
                                {{ $item->fee?->name_ru ?? $item->description }}
                                @if($period = \App\Support\InvoiceItemPeriodLabel::forItem($item))
                                    <div class="small text-muted">{{ $period }}</div>
                                @endif
                                @if($installmentCount > 0)
                                    <div class="small text-muted installment-available-note">
                                        @if($awaitingInstallmentChoice)
                                            Выберите период оплаты
                                        @else
                                            Доступно по выбранному периоду: <span class="installment-available-amount">{{ $effectiveRemaining }}</span> EGP
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">{{ $item->amount }} EGP</td>
                            <td class="text-end">{{ $paidByItem->get($item->id, '0.00') }} EGP</td>
                            <td class="text-end fw-semibold">{{ $wholeInvoiceRemaining }} EGP</td>
                            <td class="text-end">
                                <input type="number" step="0.01" min="0" max="{{ $effectiveRemaining }}"
                                       name="allocations[{{ $item->id }}]"
                                       value="{{ old('allocations.'.$item->id) }}"
                                       class="form-control form-control-sm allocation-input text-end"
                                       data-item-id="{{ $item->id }}"
                                       @disabled($awaitingInstallmentChoice || bccomp($effectiveRemaining, '0.00', 2) <= 0)
                                       form="payment-form">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div class="small text-muted mt-2">Сумма по услугам должна совпадать с суммой платежа ниже. Оставьте поле пустым, чтобы не оплачивать эту услугу сейчас.</div>
            @endif

            @if($paidItems->isNotEmpty())
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-link px-0" data-bs-toggle="collapse" data-bs-target="#paid-services">Показать оплаченные услуги ({{ $paidItems->count() }})</button>
                    <div class="collapse" id="paid-services">
                        <table class="table table-sm mb-0"><tbody>
                        @foreach($paidItems as $item)
                            <tr>
                                <td>{{ $item->fee?->name_ru ?? $item->description }}</td>
                                <td class="text-end text-muted">{{ $item->amount }} EGP — оплачено полностью</td>
                            </tr>
                        @endforeach
                        </tbody></table>
                    </div>
                </div>
            @endif
        @else
            {{-- Ambiguous multi-item invoice: a historical unattributed/partial
                 payment or refund makes each item's true remaining unknowable —
                 informational breakdown only, payment stays invoice-level, exactly
                 as before. --}}
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
        @endif
    </div></div>

    <form id="payment-form" method="POST" action="{{ route('dashboard.invoices.payments.store',$invoice) }}" class="card border-0 shadow-sm"><div class="card-body row g-3">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        @if(! $installmentCount)
            {{-- No installment schedule exists yet for this invoice (its first
                 payment will create one automatically) — nothing to submit here. --}}
        @endif

        <div class="col-md-4">
            <label class="form-label">Сумма платежа, EGP</label>
            <input id="payment-amount" type="number" step="0.01" min="0.01"
                   max="{{ $itemized ? '' : $invoice->remaining_amount }}"
                   name="amount" value="{{ old('amount') }}"
                   @if($itemized) readonly @endif
                   class="form-control" required>
        </div>
        <div class="col-md-4">
            <div class="form-label">Остаток после оплаты, EGP</div>
            <div class="form-control-plaintext fw-semibold" id="remaining-after-payment" data-invoice-remaining="{{ $invoice->remaining_amount }}">{{ $invoice->remaining_amount }}</div>
        </div>

        <div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" id="payment-method" class="form-select" required><option value="cash">Наличные</option><option value="card">Банковская карта</option><option value="bank">Банковский перевод</option><option value="instapay">InstaPay</option></select></div>
        <div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" id="cash-account" class="form-select" required><option value="">Выберите кассу</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" data-cash-drawer="{{ $account->type === \App\Models\CashAccount::TYPE_CASH ? '1' : '0' }}">{{ $account->name }}</option>@endforeach</select><div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div><div class="form-text" id="cash-account-manual-hint">Выберите кассу, в которую фактически поступили наличные.</div></div>
        <div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control">{{ old('notes') }}</textarea></div>
        <div class="col-12"><div class="small text-muted mb-3">Дата и время платежа фиксируются системой при проведении.</div><button class="btn btn-success">Принять оплату</button></div>
    </div></form>
</div>

<script>
(function () {
    // Cash-drawer / payment-method sync — unchanged from before this pass.
    const method = document.getElementById('payment-method');
    const account = document.getElementById('cash-account');
    const autoHint = document.getElementById('cash-account-auto-hint');
    const manualHint = document.getElementById('cash-account-manual-hint');
    const options = Array.from(account.options);

    function sync() {
        const isCash = method.value === 'cash';
        const isAutoRouted = ['bank', 'instapay'].includes(method.value);

        account.disabled = isAutoRouted;
        account.required = !isAutoRouted;
        autoHint.classList.toggle('d-none', !isAutoRouted);
        manualHint.classList.toggle('d-none', !isCash);

        options.forEach(option => {
            if (option.value === '') return;
            const isDrawer = option.dataset.cashDrawer === '1';
            const hide = isCash && !isDrawer;
            option.hidden = hide;
            if (hide && option.selected) account.value = '';
        });
    }
    method.addEventListener('change', sync);
    sync();
})();
</script>
<script>
(function () {
    // Payment total / Остаток после оплаты corrective — the amount field
    // is never pre-filled from "whichever installment happens to be
    // first": for an itemized invoice (single item, or allocation-clean
    // multi-item) it is a readonly mirror of the per-service inputs,
    // starting at 0.00 until the accountant actually enters something;
    // for the ambiguous case it stays the one editable field, also
    // starting empty. Остаток после оплаты is a pure client-side
    // subtraction against the invoice's own already-known remaining —
    // display only, never a source of truth the server trusts.
    const amountField = document.getElementById('payment-amount');
    const remainingLabel = document.getElementById('remaining-after-payment');
    const invoiceRemaining = parseFloat(remainingLabel.dataset.invoiceRemaining) || 0;
    const inputs = Array.from(document.querySelectorAll('.allocation-input'));
    const totalIsReadonly = amountField.hasAttribute('readonly');

    function updateRemainingAfterPayment() {
        const paidNow = parseFloat(amountField.value);
        const remaining = invoiceRemaining - (Number.isFinite(paidNow) ? paidNow : 0);
        remainingLabel.textContent = remaining.toFixed(2);
    }

    function recalculate() {
        if (!totalIsReadonly) {
            updateRemainingAfterPayment();
            return;
        }
        let total = 0;
        inputs.forEach(input => {
            const value = parseFloat(input.value);
            total += Number.isFinite(value) ? value : 0;
        });
        amountField.value = total > 0 ? total.toFixed(2) : '';
        updateRemainingAfterPayment();
    }

    inputs.forEach(input => input.addEventListener('input', recalculate));
    if (!totalIsReadonly) amountField.addEventListener('input', recalculate);
    recalculate();

    // Период оплаты — re-caps every "Оплатить сейчас" input (and its
    // note) whenever the selected installment changes. An item present
    // under the selected installment's own coverage map is capped at
    // min(period capacity, whole-invoice remaining). An item present
    // under a DIFFERENT installment's coverage map is disabled entirely
    // for this one — never falls back to its whole-invoice remaining,
    // which would let money collected "for this period" actually settle
    // a different period's own service. Only a genuine once-bucket item
    // (no coverage anywhere) uses its whole-invoice remaining once any
    // no-coverage installment (Разовые услуги, or the sole installment
    // on a simple invoice) is selected.
    const allocationTable = document.getElementById('allocation-table');
    const installmentSelect = document.getElementById('installment');

    // Ambiguous invoice (no per-service breakdown): the one editable
    // amount field's own max must still track whichever installment is
    // currently selected, exactly as the server independently enforces —
    // never auto-filled, just kept an accurate constraint.
    if (installmentSelect && !totalIsReadonly) {
        installmentSelect.addEventListener('change', () => {
            const selected = installmentSelect.selectedOptions[0];
            amountField.max = selected ? (selected.dataset.remaining || '') : '';
        });
    }

    if (allocationTable && installmentSelect) {
        const coverageRemaining = JSON.parse(allocationTable.dataset.coverageRemaining || '{}');
        const wholeInvoiceRemaining = JSON.parse(allocationTable.dataset.wholeInvoiceRemaining || '{}');
        const itemsWithSomeCoverage = new Set(JSON.parse(allocationTable.dataset.itemsWithCoverage || '[]').map(String));
        const notes = new Map(inputs.map(input => [input, input.closest('tr')?.querySelector('.installment-available-note')]));

        function syncInstallmentCaps() {
            const selectedId = installmentSelect.value;
            const perItem = selectedId ? (coverageRemaining[selectedId] || null) : null;

            inputs.forEach(input => {
                const itemId = input.dataset.itemId;
                const note = notes.get(input);
                let cap;

                if (!selectedId) {
                    cap = '0.00';
                } else if (perItem && Object.prototype.hasOwnProperty.call(perItem, itemId)) {
                    const whole = wholeInvoiceRemaining[itemId] ?? '0.00';
                    cap = parseFloat(perItem[itemId]) < parseFloat(whole) ? perItem[itemId] : whole;
                } else if (itemsWithSomeCoverage.has(itemId)) {
                    cap = '0.00';
                } else {
                    cap = wholeInvoiceRemaining[itemId] ?? '0.00';
                }

                input.max = cap;
                input.disabled = parseFloat(cap) <= 0;
                if (input.disabled) input.value = '';
                if (parseFloat(input.value) > parseFloat(cap)) input.value = cap;
                if (note) note.textContent = selectedId ? ('Доступно по выбранному периоду: ' + cap + ' EGP') : 'Выберите период оплаты';
            });
            recalculate();
        }
        installmentSelect.addEventListener('change', syncInstallmentCaps);
    }
})();
</script>
@endsection
