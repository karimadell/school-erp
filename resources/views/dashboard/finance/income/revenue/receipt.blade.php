<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Квитанция о доходе {{ $entry->reference_number }}</title>
<style>
    :root { --ink:#111827; --muted:#6b7280; --line:#d1d5db; --accent:{{ $settings->header_color }}; }
    * { box-sizing:border-box; }
    body { font-family:'Segoe UI',Arial,sans-serif; color:var(--ink); margin:0; background:#f3f4f6; }
    .page-actions { max-width:210mm; margin:16px auto 0; padding:0 8mm; display:flex; justify-content:flex-end; gap:8px; }
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

    .reversed-banner { border:2px solid #dc2626; background:#fef2f2; color:#991b1b; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-weight:700; text-align:center; text-transform:uppercase; letter-spacing:.04em; }

    .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--line); border-radius:6px; overflow:hidden; margin-bottom:14px; }
    .info-row { display:flex; justify-content:space-between; gap:10px; padding:7px 12px; border-bottom:1px solid #e5e7eb; font-size:13px; }
    .info-grid .info-row:nth-last-child(-n+2) { border-bottom:0; }
    .info-row:nth-child(odd) { border-right:1px solid #e5e7eb; }
    .info-label { color:var(--muted); }
    .info-value { font-weight:600; text-align:right; }

    .amount-block { border:2px solid var(--accent); border-radius:6px; padding:14px 16px; margin-bottom:14px; background:#fafafa; }
    .amount-label { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); font-weight:600; }
    .amount-value { font-size:30px; font-weight:800; color:var(--accent); margin-top:2px; }

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
    <button onclick="window.print()">Печать</button>
    <a href="{{ route('dashboard.finance.income.revenue.show', $entry) }}">Назад к записи</a>
</div>

<div class="receipt">
    <x-school-document-header />

    @if($entry->isReversed())
        <div class="reversed-banner">Запись сторнирована — не является действительным подтверждением дохода</div>
    @endif

    <div class="doc-title-row">
        <div class="doc-title">Квитанция о доходе</div>
        <div class="doc-meta">
            № <strong>{{ $entry->reference_number }}</strong><br>
            {{ optional($entry->posted_at)->format('d.m.Y H:i') }}
        </div>
    </div>

    <div class="info-grid">
        <div class="info-row"><span class="info-label">Категория</span><span class="info-value">{{ $entry->category?->name_ru }}</span></div>
        <div class="info-row"><span class="info-label">Дата дохода</span><span class="info-value">{{ optional($entry->revenue_date)->format('d.m.Y') }}</span></div>
        <div class="info-row"><span class="info-label">Плательщик / источник</span><span class="info-value">{{ $entry->payer_name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Способ оплаты</span><span class="info-value">{{ $entry->payment_method ? __('revenues.method_'.$entry->payment_method) : '—' }}</span></div>
        <div class="info-row"><span class="info-label">Касса / счёт</span><span class="info-value">{{ $entry->cashAccount?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Принял</span><span class="info-value">{{ $entry->poster?->name ?: $entry->creator?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Статус</span><span class="info-value">{{ __('revenues.status_'.$entry->status) }}</span></div>
        @if($entry->isReversed())
            <div class="info-row"><span class="info-label">Сторнировал</span><span class="info-value">{{ $entry->reverser?->name ?: '—' }}</span></div>
        @endif
    </div>

    <div class="amount-block">
        <div class="amount-label">{{ $entry->isReversed() ? 'Сумма (сторнирована)' : 'Сумма' }}</div>
        <div class="amount-value">{{ number_format((float) $entry->amount, 2, '.', '') }} {{ $settings->currency_symbol }}</div>
    </div>

    @if($entry->description)
        <div class="notes-block"><span class="k">Назначение:</span>{{ $entry->description }}</div>
    @endif
    @if($entry->isReversed() && $entry->reversal_reason)
        <div class="notes-block"><span class="k">Причина сторно:</span>{{ $entry->reversal_reason }}</div>
    @endif
    @if($entry->notes)
        <div class="notes-block"><span class="k">Примечание:</span>{{ $entry->notes }}</div>
    @endif

    <div class="approval">
        <div class="approval-col cashier">
            <div class="approval-role">Принял</div>
            <div class="signature-line">{{ $entry->poster?->name ?: $entry->creator?->name ?: '—' }}</div>
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
        {{ $settings->school_name }}
        @php $place = collect([$settings->city, $settings->country])->filter()->implode(', '); @endphp
        @if($place) · {{ $place }}@endif
        @if($settings->phone_1) · {{ $settings->phone_1 }}@endif
        @if($settings->email) · {{ $settings->email }}@endif
    </div>
</div>

</body>
</html>
