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
        Service-first payment collection corrective.

        createPayment() still computes exactly the same collections this
        view already relied on ($allocationClean, $remainingByItem,
        $remainingByItemPerInstallment) via InvoicePaymentService's own
        isAllocationClean()/remainingAllocatableByItem()/
        remainingByItemPerInstallment() — unchanged. The only addition is
        $coveragePeriodsByItem, a read-only reshape of InstallmentCoveragePeriod
        (real period_start/period_end, nothing invented) so each service
        can show ITS OWN applicable payment periods, instead of the
        accountant starting from one global, unexplained "Период оплаты"
        dropdown.

        The write contract is completely unchanged: exactly one
        invoice_installment_id (now a hidden field, resolved from the
        accountant's own service/period choices instead of a visible
        dropdown) plus allocations[item_id]. Because the server only ever
        accepts one installment per payment, this view enforces the same
        rule client-side the moment ANY amount is entered: every input
        whose own required installment differs from the one just implied
        becomes disabled with an explanatory note — the accountant can
        never construct a combination the server would reject for that
        reason. "Оплатить полностью" fills a service's own input to
        exactly its already-capped, already-safe capacity — never a
        cross-installment sum.

        Ambiguous multi-item invoices (a historical unattributed/partial
        payment or refund exists) keep the exact safe fallback established
        before this pass: an invoice-level amount and a plain installment
        list, no per-service breakdown — there is no reliable per-item
        figure to build one from, and this view never guesses one.
    --}}
    @php
        $items = $invoice->items;
        $isMultiItem = $items->count() > 1;
        $isAmbiguous = $isMultiItem && ! ($allocationClean ?? false);
        $itemized = ! $isAmbiguous; // single item, or allocation-clean multi-item.

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

        // The once-bucket (or the sole, simple) installment: whichever
        // remaining installment carries no InstallmentCoveragePeriod row
        // of its own at all — the same, unchanged signal already used to
        // label "Разовые услуги" before this pass, now used only to
        // resolve which installment a once-off service's payment targets,
        // never shown to the accountant by that internal name.
        $coveredInstallmentIds = $itemized ? $coveragePeriodsByItem->flatten(1)->pluck('invoice_installment_id')->unique() : collect();
        $onceInstallment = $invoice->installments->first(fn ($installment) => ! $coveredInstallmentIds->contains($installment->id));

        // One view-model row per outstanding service — built once here,
        // reused by both the markup below and nothing else (no JS data
        // blob needed beyond what each input's own data-* attributes
        // already carry).
        $serviceRows = $outstandingItems->map(function ($item) use ($wholeRemainingByItem, $paidByItem, $coveragePeriodsByItem, $remainingByItemPerInstallment, $invoice, $onceInstallment) {
            $whole = (string) $wholeRemainingByItem->get($item->id, '0.00');
            $rawPeriods = $coveragePeriodsByItem->get($item->id, collect());

            if ($rawPeriods->isEmpty()) {
                $capacity = $whole;
                if ($onceInstallment) {
                    $installmentRemaining = (string) $onceInstallment->remaining_amount;
                    $capacity = bccomp($installmentRemaining, $whole, 2) < 0 ? $installmentRemaining : $whole;
                }

                return [
                    'item' => $item, 'whole_remaining' => $whole, 'paid' => $paidByItem->get($item->id, '0.00'),
                    'once_bucket' => true, 'installment_id' => $onceInstallment?->id, 'capacity' => $capacity,
                    'periods' => collect(),
                ];
            }

            $periods = $rawPeriods->map(function ($period) use ($invoice, $whole, $remainingByItemPerInstallment, $item) {
                $installment = $invoice->installments->firstWhere('id', $period->invoice_installment_id);
                if (! $installment) {
                    return null;
                }
                $capacity = $remainingByItemPerInstallment->get($installment->id, collect())->get($item->id);
                if ($capacity === null) {
                    $capacity = (string) $installment->remaining_amount;
                }
                $capacity = bccomp($capacity, $whole, 2) < 0 ? $capacity : $whole;

                return [
                    'installment_id' => $installment->id, 'period_start' => $period->period_start, 'period_end' => $period->period_end,
                    'capacity' => $capacity, 'installment_remaining' => (string) $installment->remaining_amount,
                ];
            })->filter()->filter(fn ($p) => bccomp($p['capacity'], '0.00', 2) > 0)->sortBy('period_start')->values();

            return [
                'item' => $item, 'whole_remaining' => $whole, 'paid' => $paidByItem->get($item->id, '0.00'),
                'once_bucket' => false, 'installment_id' => null, 'capacity' => null,
                'periods' => $periods,
            ];
        });
    @endphp

    @if($itemized)
        <div class="mb-3">
            <h2 class="h6 mb-3">Что оплачивает родитель?</h2>

            @if($serviceRows->isEmpty())
                <div class="text-muted small">По этому счёту нет услуг с непогашенным остатком.</div>
            @endif

            @foreach($serviceRows as $row)
                @php $item = $row['item']; @endphp
                <div class="card border-0 shadow-sm mb-3 service-card" data-item-id="{{ $item->id }}">
                    <div class="card-body">
                        <h3 class="h6 mb-2">{{ $item->fee?->name_ru ?? $item->description }}</h3>
                        <div class="row g-2 small text-muted mb-3">
                            <div class="col-4">Начислено: {{ $item->amount }} EGP</div>
                            <div class="col-4">Оплачено: {{ $row['paid'] }} EGP</div>
                            <div class="col-4">Задолженность: <strong class="text-danger">{{ $row['whole_remaining'] }} EGP</strong></div>
                        </div>

                        @if($row['once_bucket'])
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="number" step="0.01" min="0" max="{{ $row['capacity'] }}"
                                       name="allocations[{{ $item->id }}]"
                                       value="{{ old('allocations.'.$item->id) }}"
                                       class="form-control service-input" style="max-width:200px"
                                       data-item-id="{{ $item->id }}"
                                       data-installment-id="{{ $row['installment_id'] }}"
                                       @disabled(bccomp($row['capacity'], '0.00', 2) <= 0)
                                       form="payment-form">
                                <button type="button" class="btn btn-outline-primary btn-sm pay-full-btn">Оплатить полностью</button>
                                <span class="small text-muted service-lock-note d-none">Этот платёж относится к другому расчётному периоду. Оформите его отдельным платежом.</span>
                            </div>
                        @elseif($row['periods']->count() === 1)
                            @php $period = $row['periods']->first(); @endphp
                            <div class="small text-muted mb-2">{{ $period['period_start']->format('d.m.Y') }} – {{ $period['period_end']->format('d.m.Y') }} · остаток по периоду {{ $period['capacity'] }} EGP</div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="number" step="0.01" min="0" max="{{ $period['capacity'] }}"
                                       name="allocations[{{ $item->id }}]"
                                       value="{{ old('allocations.'.$item->id) }}"
                                       class="form-control service-input" style="max-width:200px"
                                       data-item-id="{{ $item->id }}"
                                       data-installment-id="{{ $period['installment_id'] }}"
                                       @disabled(bccomp($period['capacity'], '0.00', 2) <= 0)
                                       form="payment-form">
                                <button type="button" class="btn btn-outline-primary btn-sm pay-full-btn">Оплатить полностью</button>
                                <span class="small text-muted service-lock-note d-none">Этот платёж относится к другому расчётному периоду. Оформите его отдельным платежом.</span>
                            </div>
                        @else
                            <label class="form-label small text-muted d-block">Период оплаты</label>
                            <div class="mb-2">
                                @foreach($row['periods'] as $period)
                                    <div class="form-check">
                                        <input class="form-check-input period-radio" type="radio"
                                               name="period_choice_{{ $item->id }}"
                                               id="period-{{ $item->id }}-{{ $period['installment_id'] }}"
                                               data-item-id="{{ $item->id }}"
                                               data-installment-id="{{ $period['installment_id'] }}"
                                               data-capacity="{{ $period['capacity'] }}">
                                        <label class="form-check-label small" for="period-{{ $item->id }}-{{ $period['installment_id'] }}">
                                            {{ $period['period_start']->format('d.m.Y') }} – {{ $period['period_end']->format('d.m.Y') }} · остаток {{ $period['capacity'] }} EGP
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="number" step="0.01" min="0"
                                       name="allocations[{{ $item->id }}]"
                                       value="{{ old('allocations.'.$item->id) }}"
                                       class="form-control service-input" style="max-width:200px"
                                       data-item-id="{{ $item->id }}"
                                       disabled
                                       form="payment-form">
                                <button type="button" class="btn btn-outline-primary btn-sm pay-full-btn">Оплатить полностью</button>
                                <span class="small text-muted service-period-hint">Выберите период оплаты выше</span>
                                <span class="small text-muted service-lock-note d-none">Этот платёж относится к другому расчётному периоду. Оформите его отдельным платежом.</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            @if($paidItems->isNotEmpty())
                <div class="mb-3">
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
        </div>
    @else
        {{-- Ambiguous multi-item invoice: a historical unattributed/partial
             payment or refund makes each item's true remaining unknowable —
             the existing safe invoice-level fallback, unchanged. --}}
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h6 mb-3">Состав счёта</h2>
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

            @if($invoice->installments->isNotEmpty())
                @php $ambiguousInstallmentChoiceRequired = $invoice->installments->count() > 1; @endphp
                <div class="mt-3">
                    <label class="form-label small text-muted" for="ambiguous-installment">Расчётный период</label>
                    @if($ambiguousInstallmentChoiceRequired)
                        <select name="invoice_installment_id" id="ambiguous-installment" class="form-select" required form="payment-form">
                            <option value="" selected disabled>— Выберите расчётный период —</option>
                            @foreach($invoice->installments as $installment)
                                <option value="{{ $installment->id }}" data-remaining="{{ $installment->remaining_amount }}" @selected(old('invoice_installment_id') == $installment->id)>до {{ $installment->due_date->format('d.m.Y') }} · остаток {{ $installment->remaining_amount }} EGP</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" name="invoice_installment_id" value="{{ $invoice->installments->first()->id }}" form="payment-form">
                        <div class="form-control-plaintext">до {{ $invoice->installments->first()->due_date->format('d.m.Y') }}</div>
                    @endif
                </div>
            @endif
        </div></div>
    @endif

    {{-- К оплате — live summary of everything currently entered above. --}}
    <div class="card border-0 shadow-sm mb-4" id="payment-summary-card">
        <div class="card-body">
            <h2 class="h6 mb-2">К оплате</h2>
            <ul class="list-unstyled mb-2" id="payment-summary-lines"></ul>
            <div class="text-muted small" id="payment-summary-empty">Пока ничего не выбрано.</div>
        </div>
    </div>

    <form id="payment-form" method="POST" action="{{ route('dashboard.invoices.payments.store',$invoice) }}" class="card border-0 shadow-sm"><div class="card-body row g-3">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        @if($itemized)
            <input type="hidden" name="invoice_installment_id" id="installment-id-field" value="{{ old('invoice_installment_id') }}">
        @endif

        <div class="col-md-4">
            <label class="form-label">Итого к оплате, EGP</label>
            <input id="payment-amount" type="number" step="0.01" min="0.01"
                   max="{{ $itemized ? '' : $invoice->remaining_amount }}"
                   name="amount" value="{{ old('amount') }}"
                   @if($itemized) readonly @endif
                   class="form-control" required>
        </div>
        <div class="col-md-4">
            <div class="form-label">Останется задолженность, EGP</div>
            <div class="form-control-plaintext fw-semibold" id="remaining-after-payment" data-invoice-remaining="{{ $invoice->remaining_amount }}">{{ $invoice->remaining_amount }}</div>
        </div>

        <div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" id="payment-method" class="form-select" required><option value="cash">Наличные</option><option value="card">Банковская карта</option><option value="bank">Банковский перевод</option><option value="instapay">InstaPay</option></select></div>
        <div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" id="cash-account" class="form-select" required><option value="">Выберите кассу</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" data-cash-drawer="{{ $account->type === \App\Models\CashAccount::TYPE_CASH ? '1' : '0' }}">{{ $account->name }}</option>@endforeach</select><div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div><div class="form-text" id="cash-account-manual-hint">Выберите кассу, в которую фактически поступили наличные.</div></div>
        <div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control">{{ old('notes') }}</textarea></div>
        <div class="col-12"><div class="small text-muted mb-3">Дата и время платежа фиксируются системой при проведении.</div><button type="submit" class="btn btn-success" id="submit-payment-btn" disabled>Принять оплату</button></div>
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
    const amountField = document.getElementById('payment-amount');
    const remainingLabel = document.getElementById('remaining-after-payment');
    const invoiceRemaining = parseFloat(remainingLabel.dataset.invoiceRemaining) || 0;
    const submitBtn = document.getElementById('submit-payment-btn');
    const summaryLines = document.getElementById('payment-summary-lines');
    const summaryEmpty = document.getElementById('payment-summary-empty');
    const totalIsReadonly = amountField.hasAttribute('readonly');
    const serviceInputs = Array.from(document.querySelectorAll('.service-input'));

    function updateRemainingAfterPayment(total) {
        const remaining = invoiceRemaining - (Number.isFinite(total) ? total : 0);
        remainingLabel.textContent = remaining.toFixed(2);
    }

    function serviceLabel(input) {
        const card = input.closest('.service-card');
        const name = card ? card.querySelector('h3')?.textContent?.trim() : '';
        const checkedRadio = card ? card.querySelector('.period-radio:checked') : null;
        const periodLabel = checkedRadio ? checkedRadio.closest('.form-check').querySelector('label')?.textContent?.split('·')[0]?.trim() : null;
        return periodLabel ? (name + ' — ' + periodLabel) : name;
    }

    function recalculate() {
        let total = 0;

        if (totalIsReadonly) {
            summaryLines.innerHTML = '';
            let any = false;
            serviceInputs.forEach(input => {
                const value = parseFloat(input.value);
                if (Number.isFinite(value) && value > 0) {
                    any = true;
                    total += value;
                    const li = document.createElement('li');
                    li.className = 'd-flex justify-content-between';
                    li.innerHTML = '<span>' + serviceLabel(input) + '</span><span>' + value.toFixed(2) + ' EGP</span>';
                    summaryLines.appendChild(li);
                }
            });
            summaryEmpty.classList.toggle('d-none', any);
            amountField.value = total > 0 ? total.toFixed(2) : '';
        } else {
            const value = parseFloat(amountField.value);
            total = Number.isFinite(value) ? value : 0;
            summaryLines.innerHTML = '';
            summaryEmpty.classList.toggle('d-none', total <= 0);
            if (total > 0) {
                const li = document.createElement('li');
                li.className = 'd-flex justify-content-between';
                li.innerHTML = '<span>Платёж по счёту</span><span>' + total.toFixed(2) + ' EGP</span>';
                summaryLines.appendChild(li);
            }
        }

        updateRemainingAfterPayment(total);
        submitBtn.disabled = !(total > 0);
    }

    if (!totalIsReadonly) {
        amountField.addEventListener('input', recalculate);
        const ambiguousInstallment = document.getElementById('ambiguous-installment');
        if (ambiguousInstallment) {
            ambiguousInstallment.addEventListener('change', event => {
                const selected = event.target.selectedOptions[0];
                amountField.max = selected ? (selected.dataset.remaining || '') : '';
            });
        }
        recalculate();
        return;
    }

    // Service-first locking — the server accepts exactly ONE
    // invoice_installment_id per payment (InvoicePaymentService::record()
    // is unchanged). The moment any service input carries a positive
    // amount, its own installment becomes the "locked" one: every OTHER
    // input whose own installment differs is disabled with an
    // explanatory note, so the accountant can never build a submission
    // the server would reject for mixing installments. Clearing every
    // input unlocks again.
    const installmentField = document.getElementById('installment-id-field');
    const periodRadios = Array.from(document.querySelectorAll('.period-radio'));

    function activeInstallmentId() {
        for (const input of serviceInputs) {
            const value = parseFloat(input.value);
            if (Number.isFinite(value) && value > 0 && input.dataset.installmentId) {
                return input.dataset.installmentId;
            }
        }
        return null;
    }

    function syncLock() {
        const active = activeInstallmentId();
        installmentField.value = active || '';

        serviceInputs.forEach(input => {
            const card = input.closest('.service-card');
            const note = card ? card.querySelector('.service-lock-note') : null;
            const ownInstallmentId = input.dataset.installmentId || null;

            if (!ownInstallmentId) {
                // Multi-period service awaiting a radio choice — left to
                // the radio-sync logic below, not the lock.
                return;
            }

            const blockedByLock = !!active && ownInstallmentId !== active;
            input.disabled = blockedByLock || parseFloat(input.max || '0') <= 0;
            if (blockedByLock) input.value = '';
            if (note) note.classList.toggle('d-none', !blockedByLock);
        });

        periodRadios.forEach(radio => {
            const blockedByLock = !!active && radio.dataset.installmentId !== active;
            radio.disabled = blockedByLock;
            if (blockedByLock && radio.checked) {
                radio.checked = false;
                const input = document.querySelector('.service-input[data-item-id="' + radio.dataset.itemId + '"]');
                if (input) {
                    input.value = '';
                    input.disabled = true;
                    delete input.dataset.installmentId;
                    input.max = '';
                    const hint = input.closest('.service-card')?.querySelector('.service-period-hint');
                    if (hint) hint.classList.remove('d-none');
                }
            }
        });

        recalculate();
    }

    periodRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            const input = document.querySelector('.service-input[data-item-id="' + radio.dataset.itemId + '"]');
            if (!input) return;
            input.dataset.installmentId = radio.dataset.installmentId;
            input.max = radio.dataset.capacity;
            input.disabled = parseFloat(radio.dataset.capacity) <= 0;
            const hint = input.closest('.service-card')?.querySelector('.service-period-hint');
            if (hint) hint.classList.add('d-none');
            syncLock();
        });
    });

    serviceInputs.forEach(input => input.addEventListener('input', syncLock));

    document.querySelectorAll('.pay-full-btn').forEach(button => {
        button.addEventListener('click', () => {
            const input = button.closest('.d-flex')?.querySelector('.service-input');
            if (!input || input.disabled) return;
            input.value = input.max;
            syncLock();
        });
    });

    syncLock();
})();
</script>
@endsection
