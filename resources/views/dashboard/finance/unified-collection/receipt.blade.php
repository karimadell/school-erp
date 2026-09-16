<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Квитанция о сборе оплаты {{ $collection->collection_number }}</title>
<style>
    :root { --ink:#111827; --muted:#6b7280; --line:#d1d5db; --accent:{{ $settings->header_color }}; }
    * { box-sizing:border-box; }
    body { font-family:'Segoe UI',Arial,sans-serif; color:var(--ink); margin:0; background:#f3f4f6; }
    .page-actions { max-width:210mm; margin:16px auto 0; padding:0 8mm; display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
    .page-actions button, .page-actions a {
        border:1px solid var(--line); background:#fff; color:var(--ink); padding:8px 16px; border-radius:6px;
        font-size:13px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    }
    .page-actions .primary { background:var(--accent); color:#fff; border-color:var(--accent); }

    .receipt {
        max-width:210mm; margin:12px auto 32px; background:#fff; padding:14mm 12mm;
        border:1px solid var(--line); box-shadow:0 1px 3px rgba(0,0,0,.08);
    }

    .doc-title-row { display:flex; align-items:baseline; justify-content:space-between; margin:6px 0 16px; flex-wrap:wrap; gap:6px; }
    .doc-title { font-size:20px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; }
    .doc-meta { text-align:right; font-size:12.5px; color:var(--muted); }
    .doc-meta strong { color:var(--ink); font-size:13.5px; }

    .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--line); border-radius:6px; overflow:hidden; margin-bottom:14px; }
    .info-row { display:flex; justify-content:space-between; gap:10px; padding:7px 12px; border-bottom:1px solid #e5e7eb; font-size:13px; }
    .info-grid .info-row:nth-last-child(-n+2) { border-bottom:0; }
    .info-row:nth-child(odd) { border-right:1px solid #e5e7eb; }
    .info-label { color:var(--muted); }
    .info-value { font-weight:600; text-align:right; }

    .amount-block { border:2px solid var(--accent); border-radius:6px; padding:14px 16px; margin-bottom:14px; background:#fafafa; }
    .amount-label { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); font-weight:600; }
    .amount-value { font-size:30px; font-weight:800; color:var(--accent); margin-top:2px; }

    .section-title { font-size:12.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin:16px 0 8px; }

    .lines-table { width:100%; border-collapse:collapse; border:1px solid var(--line); border-radius:6px; overflow:hidden; margin-bottom:14px; font-size:13px; }
    .lines-table th, .lines-table td { padding:8px 10px; border-bottom:1px solid #e5e7eb; text-align:left; }
    .lines-table th { background:#f9fafb; color:var(--muted); font-weight:600; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; }
    .lines-table tr:last-child td { border-bottom:0; }
    .lines-table td.amount { text-align:right; font-weight:600; white-space:nowrap; }
    .lines-table .sub { display:block; color:var(--muted); font-size:11.5px; margin-top:2px; }
    .refund-flag { display:inline-block; margin-top:4px; padding:2px 8px; border-radius:10px; background:#fff8db; border:1px solid #f5c542; color:#7c5e00; font-size:11px; font-weight:600; }

    .notes-block { border:1px solid var(--line); border-radius:6px; padding:10px 12px; margin-bottom:18px; font-size:13px; }
    .notes-block .k { color:var(--muted); display:block; margin-bottom:3px; }

    .approval { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:26px; padding-top:16px; border-top:1px solid var(--line); }
    .approval-col { text-align:center; font-size:12.5px; }
    .approval-role { font-weight:700; margin-bottom:34px; }
    .approval-col.cashier .approval-role, .approval-col.director .approval-role { margin-bottom:6px; }
    .signature-slot { height:34px; display:flex; align-items:flex-end; justify-content:center; margin-bottom:4px; }
    .signature-slot img { max-height:32px; max-width:100%; object-fit:contain; }
    .signature-line { border-top:1px solid var(--ink); margin-top:2px; padding-top:4px; }
    .stamp-slot { height:40mm; display:flex; align-items:center; justify-content:center; }
    .stamp-slot img { max-height:36mm; max-width:36mm; object-fit:contain; }
    .stamp-caption { font-weight:700; letter-spacing:.05em; margin-top:2px; }

    .receipt-footer { margin-top:22px; padding-top:10px; border-top:1px solid var(--line); text-align:center; font-size:11.5px; color:var(--muted); }
    .receipt-footer .thanks { font-size:12.5px; color:var(--ink); font-weight:600; margin-bottom:4px; }

    @media print {
        body { background:#fff; }
        .page-actions { display:none; }
        .receipt { box-shadow:none; border:0; margin:0 auto; padding:8mm 10mm; max-width:none; }
        @page { size:A4; margin:10mm; }
    }
</style>
</head>
<body>

<div class="page-actions">
    <button onclick="window.print()">🖨️ Печать</button>
    <a class="primary" href="{{ route('dashboard.collections.receipt.pdf', $collection) }}">⬇ Скачать PDF</a>
    @if($collection->student)
        <a href="{{ route('dashboard.students.finance', $collection->student) }}">Финансовый счёт ученика</a>
        <a href="{{ route('dashboard.students.unified-collection.create', $collection->student) }}">Новый сбор оплаты</a>
    @endif
</div>

<div class="receipt">
    <x-school-document-header :academic-year="$collection->academicYear?->name" />

    <div class="doc-title-row">
        <div class="doc-title">КВИТАНЦИЯ О СБОРЕ ОПЛАТЫ</div>
        <div class="doc-meta">
            № <strong>{{ $collection->collection_number }}</strong><br>
            {{ ($collection->completed_at ?? $collection->created_at)?->format('d.m.Y H:i') }}
        </div>
    </div>

    <div class="info-grid">
        <div class="info-row"><span class="info-label">Ученик</span><span class="info-value">{{ $collection->student?->full_name }}</span></div>
        <div class="info-row"><span class="info-label">Учебный год</span><span class="info-value">{{ $collection->academicYear?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Дата сбора</span><span class="info-value">{{ ($collection->completed_at ?? $collection->created_at)?->format('d.m.Y H:i') }}</span></div>
        <div class="info-row"><span class="info-label">Способ оплаты</span><span class="info-value">{{ $methodLabels[$collection->payment_method] ?? $collection->payment_method }}</span></div>
        <div class="info-row"><span class="info-label">Касса</span><span class="info-value">{{ $collection->cashAccount?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Кассир</span><span class="info-value">{{ $collection->creator?->name ?: 'Не указан' }}</span></div>
    </div>

    <div class="amount-block">
        <div class="amount-label">Получено (весь сбор)</div>
        <div class="amount-value">{{ number_format((float) $grossTotal, 2, '.', '') }} {{ $settings->currency_symbol }}</div>
    </div>

    {{--
        Unified Collection Receipt (PR C2) — one FinanceCollection can span
        several InvoicePayment rows (existing obligations paid down, and/or
        a newly-issued charge settled via the mixed orchestrator). Every row
        below is one of THIS collection's own persisted InvoicePayment
        records (finance_collection_id-linked) — never a fabricated or
        re-derived line. A payment's own PaymentAllocation rows are shown
        when they exist, exactly like the single-payment receipt already
        does; a later refund against a row is surfaced only as an
        informational flag and never changes the amount shown here (see
        FinanceCollection::grossReceivedTotal()'s own docblock).
    --}}
    <div class="section-title">Платежи в составе сбора</div>
    <table class="lines-table">
        <thead>
            <tr>
                <th>Счёт</th>
                <th>Способ оплаты</th>
                <th>Платёж №</th>
                <th>Касс. операция</th>
                <th class="amount">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $payment)
                <tr>
                    <td>
                        {{ $payment->invoice?->display_number ?? '—' }}
                        @if($payment->allocations->isNotEmpty())
                            <span class="sub">{{ $payment->allocations->map(fn ($allocation) => $allocation->item?->fee?->name_ru ?? $allocation->item?->description ?? '—')->implode(', ') }}</span>
                        @endif
                        @if($payment->refunds->isNotEmpty())
                            <span class="refund-flag">По этому платежу был оформлен возврат — сумма ниже показана как получено изначально</span>
                        @endif
                    </td>
                    <td>{{ $methodLabels[$payment->payment_method] ?? $payment->payment_method }}</td>
                    <td>{{ $payment->payment_number }}</td>
                    <td>{{ $payment->cashTransaction?->id ? '№'.$payment->cashTransaction->id : '—' }}</td>
                    <td class="amount">{{ number_format((float) $payment->amount, 2, '.', '') }} {{ $settings->currency_symbol }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($linkedInvoices->isNotEmpty())
        <div class="section-title">Счета, затронутые этим сбором</div>
        <table class="lines-table">
            <thead>
                <tr>
                    <th>Счёт</th>
                    <th class="amount">Итого по счёту</th>
                    <th class="amount">Остаток</th>
                </tr>
            </thead>
            <tbody>
                @foreach($linkedInvoices as $invoice)
                    <tr>
                        <td>{{ $invoice->display_number }}</td>
                        <td class="amount">{{ number_format((float) $invoice->total_amount, 2, '.', '') }} {{ $settings->currency_symbol }}</td>
                        <td class="amount">{{ number_format((float) $invoice->remaining_amount, 2, '.', '') }} {{ $settings->currency_symbol }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($collection->notes)
        <div class="notes-block"><span class="k">Примечание:</span>{{ $collection->notes }}</div>
    @endif

    <div class="approval">
        <div class="approval-col cashier">
            <div class="approval-role">Кассир</div>
            <div class="signature-line">{{ $collection->creator?->name ?: '—' }}</div>
        </div>
        <div class="approval-col stamp">
            <div class="stamp-slot">
                @if($settings->stampAsset())
                    <img src="{{ $settings->stampAsset()['data_uri'] }}" alt="Официальная печать школы">
                @endif
            </div>
            <div class="stamp-caption">М.П.</div>
        </div>
        <div class="approval-col director">
            <div class="approval-role">Директор</div>
            <div class="signature-slot">
                @if($settings->directorSignatureAsset())
                    <img src="{{ $settings->directorSignatureAsset()['data_uri'] }}" alt="Подпись директора">
                @endif
            </div>
            <div class="signature-line">&nbsp;</div>
        </div>
    </div>

    <div class="receipt-footer">
        <div class="thanks">Спасибо, что выбрали нашу школу!</div>
        {{ $settings->school_name }}
        @php $place = collect([$settings->city, $settings->country])->filter()->implode(', '); @endphp
        @if($place) · {{ $place }}@endif
        @if($settings->phone_1) · {{ $settings->phone_1 }}@endif
        @if($settings->email) · {{ $settings->email }}@endif
    </div>
</div>

</body>
</html>
