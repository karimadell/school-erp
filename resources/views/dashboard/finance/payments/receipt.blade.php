<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Квитанция об оплате {{ $payment->payment_number }}</title>
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

    .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--line); border-radius:6px; overflow:hidden; margin-bottom:14px; }
    .info-row { display:flex; justify-content:space-between; gap:10px; padding:7px 12px; border-bottom:1px solid #e5e7eb; font-size:13px; }
    .info-grid .info-row:nth-last-child(-n+2) { border-bottom:0; }
    .info-row:nth-child(odd) { border-right:1px solid #e5e7eb; }
    .info-label { color:var(--muted); }
    .info-value { font-weight:600; text-align:right; }

    .amount-block { border:2px solid var(--accent); border-radius:6px; padding:14px 16px; margin-bottom:14px; background:#fafafa; }
    .amount-label { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); font-weight:600; }
    .amount-value { font-size:30px; font-weight:800; color:var(--accent); margin-top:2px; }

    .breakdown { display:grid; grid-template-columns:repeat(4,1fr); gap:0; margin-top:12px; border-top:1px solid #e5e7eb; padding-top:10px; }
    .breakdown div { padding:0 10px; border-right:1px solid #e5e7eb; }
    .breakdown div:last-child { border-right:0; }
    .breakdown .k { font-size:11px; color:var(--muted); }
    .breakdown .v { font-size:14px; font-weight:700; margin-top:2px; }

    .installment-block { border:1px solid var(--line); border-radius:6px; padding:10px 12px; margin-bottom:14px; font-size:13px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .installment-block .k { color:var(--muted); margin-right:6px; }

    .notice { border:1px solid #f5c542; background:#fff8db; border-radius:6px; padding:10px 12px; margin-bottom:14px; font-weight:600; font-size:13px; }

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
    .name-caption { color:var(--muted); margin-top:2px; }

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
    <a class="primary" id="receipt-pdf" href="{{ route('dashboard.payments.receipt.pdf',$payment) }}">⬇ Скачать PDF</a>
	    <button type="button" id="share-receipt">{{ __('finance_uat.share') }}</button>
	    @if($shareRecipient)
	        <a href="{{ \App\Support\FinanceShareRecipient::whatsappUrl($shareRecipient, __('finance_uat.whatsapp_receipt_message',['number'=>$payment->payment_number,'amount'=>number_format((float)$payment->amount,2,'.','')])) }}" target="_blank" rel="noopener noreferrer">{{ __('finance_uat.send_whatsapp') }}</a>
	    @else
	        <button type="button" disabled title="{{ __('finance_uat.phone_missing') }}">{{ __('finance_uat.phone_missing') }}</button>
	    @endif
    <a href="{{ route('dashboard.invoices.show', $invoice) }}">Просмотр счёта</a>
    @if($invoice->student)
        <a href="{{ route('dashboard.students.show', $invoice->student) }}">Профиль ученика</a>
    @endif
    @if(bccomp((string) $invoice->remaining_amount, '0.00', 2) > 0)
        <a class="primary" href="{{ route('dashboard.invoices.payments.create', $invoice) }}">Следующий платёж</a>
    @elseif($invoice->student)
        <a class="primary" href="{{ route('dashboard.students.finance', $invoice->student) }}">Новый платёж</a>
    @endif
</div>

<div class="receipt">
    <x-school-document-header :academic-year="$invoice->academicYear?->name" />

    <div class="doc-title-row">
        <div class="doc-title">КВИТАНЦИЯ ОБ ОПЛАТЕ</div>
        <div class="doc-meta">
            № <strong>{{ $payment->payment_number }}</strong><br>
            {{ ($payment->paid_at ?? $payment->created_at)?->format('d.m.Y H:i') }}
        </div>
    </div>

    <div class="info-grid">
        <div class="info-row"><span class="info-label">Ученик</span><span class="info-value">{{ $invoice->student?->full_name }}</span></div>
        <div class="info-row"><span class="info-label">Счёт</span><span class="info-value">{{ $invoice->display_number }}</span></div>
        <div class="info-row"><span class="info-label">Учебный год</span><span class="info-value">{{ $invoice->academicYear?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Дата платежа</span><span class="info-value">{{ ($payment->paid_at ?? $payment->created_at)?->format('d.m.Y H:i') }}</span></div>
        <div class="info-row"><span class="info-label">Способ оплаты</span><span class="info-value">{{ $methodLabels[$payment->payment_method] ?? $payment->payment_method }}</span></div>
        <div class="info-row"><span class="info-label">Касса</span><span class="info-value">{{ $payment->cashAccount?->name ?: '—' }}</span></div>
        <div class="info-row"><span class="info-label">Кассир</span><span class="info-value">{{ $payment->creator?->name ?: 'Не указан' }}</span></div>
    </div>

    <div class="amount-block">
        <div class="amount-label">Оплачено</div>
        <div class="amount-value">{{ number_format((float) $payment->amount, 2, '.', '') }} {{ $settings->currency_symbol }}</div>
        <div class="breakdown">
            <div><div class="k">Итого по счёту</div><div class="v">{{ number_format((float) $invoice->total_amount, 2, '.', '') }} {{ $settings->currency_symbol }}</div></div>
            <div><div class="k">Оплачено ранее</div><div class="v">{{ number_format((float) $previouslyPaid, 2, '.', '') }} {{ $settings->currency_symbol }}</div></div>
            <div><div class="k">Этот платёж</div><div class="v">{{ number_format((float) $payment->amount, 2, '.', '') }} {{ $settings->currency_symbol }}</div></div>
            <div><div class="k">Остаток после платежа</div><div class="v">{{ number_format((float) $remainingAfter, 2, '.', '') }} {{ $settings->currency_symbol }}</div></div>
        </div>
    </div>

    @if($payment->installment)
        <div class="installment-block">
            <div><span class="k">Этап рассрочки:</span><strong>{{ $payment->installment->name_ru }}</strong></div>
            <div><span class="k">Остаток по этапу после платежа:</span><strong>{{ number_format((float) $payment->installment->remaining_amount, 2, '.', '') }} {{ $settings->currency_symbol }}</strong></div>
        </div>
    @endif

    @if($invoice->items->contains('is_non_refundable', true))
        <div class="notice">Регистрационный взнос возврату не подлежит.</div>
    @endif

    @if($payment->notes)
        <div class="notes-block"><span class="k">Примечание:</span>{{ $payment->notes }}</div>
    @endif

    <div class="approval">
        <div class="approval-col cashier">
            <div class="approval-role">Кассир</div>
            <div class="signature-line">{{ $payment->creator?->name ?: '—' }}</div>
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

<script>
document.getElementById('share-receipt')?.addEventListener('click', async () => {
    const pdfLink = document.getElementById('receipt-pdf');
    if (!navigator.share || !navigator.canShare) { window.location.assign(pdfLink.href); return; }
    try {
        const response = await fetch(pdfLink.href, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('PDF download failed');
        const file = new File([await response.blob()], @json(($payment->payment_number ?: 'payment').'.pdf'), { type: 'application/pdf' });
        if (!navigator.canShare({ files: [file] })) { window.location.assign(pdfLink.href); return; }
        await navigator.share({ title: @json('Квитанция '.$payment->payment_number), files: [file] });
    } catch (error) { if (error.name !== 'AbortError') window.location.assign(pdfLink.href); }
});
</script>

</body>
</html>
