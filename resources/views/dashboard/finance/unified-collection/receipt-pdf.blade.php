<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
    body { font-family:DejaVu Sans, sans-serif; font-size:11.5px; color:#111827; margin:0; }
    table { border-collapse:collapse; width:100%; }
    .doc-title-row { width:100%; margin:6px 0 14px; }
    .doc-title-row td { vertical-align:bottom; }
    .doc-title { font-size:18px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; }
    .doc-meta { text-align:right; font-size:11px; color:#6b7280; }
    .doc-meta strong { color:#111827; font-size:12px; }

    .info-table { border:1px solid #d1d5db; margin-bottom:12px; }
    .info-table td { border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; padding:6px 10px; width:25%; font-size:11px; }
    .info-table tr:last-child td { border-bottom:0; }
    .info-table td:nth-child(2n) { border-right:0; }
    .info-table .k { color:#6b7280; display:block; }
    .info-table .v { font-weight:700; }

    .amount-block { border:2px solid {{ $settings->header_color }}; padding:12px 14px; margin-bottom:12px; }
    .amount-label { font-size:10px; letter-spacing:1px; text-transform:uppercase; color:#6b7280; font-weight:700; }
    .amount-value { font-size:24px; font-weight:700; color:{{ $settings->header_color }}; margin-top:2px; }

    .section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; margin:14px 0 6px; }

    .lines-table { border:1px solid #d1d5db; margin-bottom:12px; }
    .lines-table th, .lines-table td { padding:6px 10px; border-bottom:1px solid #e5e7eb; text-align:left; font-size:10.5px; }
    .lines-table th { background:#f9fafb; color:#6b7280; font-weight:700; }
    .lines-table tr:last-child td { border-bottom:0; }
    .lines-table td.amount, .lines-table th.amount { text-align:right; }
    .lines-table .sub { display:block; color:#6b7280; font-size:10px; margin-top:2px; }

    .box { border:1px solid #d1d5db; padding:8px 10px; margin-bottom:12px; font-size:11px; }
    .box .k { color:#6b7280; margin-right:4px; }

    .approval { width:100%; margin-top:24px; padding-top:14px; border-top:1px solid #d1d5db; }
    .approval td { width:33.33%; text-align:center; font-size:11px; vertical-align:top; padding:0 8px; }
    .approval-role { font-weight:700; }
    .approval-role.spacer { margin-bottom:30px; display:block; }
    .signature-slot { height:30px; }
    .signature-slot img { max-height:28px; max-width:120px; }
    .signature-line { border-top:1px solid #111827; margin-top:3px; padding-top:3px; }
    .stamp-slot { height:40mm; text-align:center; }
    .stamp-slot img { max-height:36mm; max-width:36mm; }
    .stamp-caption { font-weight:700; letter-spacing:1px; margin-top:2px; }

    .receipt-footer { margin-top:20px; padding-top:8px; border-top:1px solid #d1d5db; text-align:center; font-size:10px; color:#6b7280; }
    .receipt-footer .thanks { font-size:11px; color:#111827; font-weight:700; margin-bottom:3px; }
</style>
</head>
<body>

@include('pdf.partials.document-header', ['documentTitle' => null])

<table class="doc-title-row"><tr>
    <td><span class="doc-title">КВИТАНЦИЯ О СБОРЕ ОПЛАТЫ</span></td>
    <td class="doc-meta">№ <strong>{{ $collection->collection_number }}</strong><br>{{ ($collection->completed_at ?? $collection->created_at)?->format('d.m.Y H:i') }}</td>
</tr></table>

<table class="info-table">
    <tr>
        <td><span class="k">Ученик</span><span class="v">{{ $collection->student?->full_name }}</span></td>
        <td><span class="k">Учебный год</span><span class="v">{{ $collection->academicYear?->name ?: '—' }}</span></td>
    </tr>
    <tr>
        <td><span class="k">Дата сбора</span><span class="v">{{ ($collection->completed_at ?? $collection->created_at)?->format('d.m.Y H:i') }}</span></td>
        <td><span class="k">Способ оплаты</span><span class="v">{{ $methodLabels[$collection->payment_method] ?? $collection->payment_method }}</span></td>
    </tr>
    <tr>
        <td><span class="k">Касса</span><span class="v">{{ $collection->cashAccount?->name ?: '—' }}</span></td>
        <td><span class="k">Кассир</span><span class="v">{{ $collection->creator?->name ?: 'Не указан' }}</span></td>
    </tr>
</table>

<div class="amount-block">
    <div class="amount-label">Получено (весь сбор)</div>
    <div class="amount-value">{{ number_format((float) $grossTotal, 2, '.', '') }} {{ $settings->currency_symbol }}</div>
</div>

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
                        <span class="sub">По этому платежу был оформлен возврат — сумма показана как получено изначально</span>
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
    <div class="box"><span class="k">Примечание:</span>{{ $collection->notes }}</div>
@endif

<table class="approval">
    <tr>
        <td>
            <span class="approval-role spacer">Кассир</span>
            <div class="signature-line">{{ $collection->creator?->name ?: '—' }}</div>
        </td>
        <td>
            <div class="stamp-slot">
                @if($settings->stampAsset())
                    <img src="{{ $settings->stampAsset()['data_uri'] }}" alt="Официальная печать школы">
                @endif
            </div>
            <div class="stamp-caption">М.П.</div>
        </td>
        <td>
            <span class="approval-role">Директор</span>
            <div class="signature-slot">
                @if($settings->directorSignatureAsset())
                    <img src="{{ $settings->directorSignatureAsset()['data_uri'] }}" alt="Подпись директора">
                @endif
            </div>
            <div class="signature-line">&nbsp;</div>
        </td>
    </tr>
</table>

<div class="receipt-footer">
    <div class="thanks">Спасибо, что выбрали нашу школу!</div>
    {{ $settings->school_name }}
    @php $place = collect([$settings->city, $settings->country])->filter()->implode(', '); @endphp
    @if($place) · {{ $place }}@endif
    @if($settings->phone_1) · {{ $settings->phone_1 }}@endif
    @if($settings->email) · {{ $settings->email }}@endif
</div>

</body>
</html>
