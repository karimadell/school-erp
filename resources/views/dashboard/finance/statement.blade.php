<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('finance_uat.student_statement') }} — {{ $student->full_name }}</title>
    <style>
        body { margin: 0; padding: 22px; color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .actions { margin-bottom: 18px; text-align: right; }
        .actions a, .actions button { padding: 8px 12px; border: 1px solid #9ca3af; background: white; text-decoration: none; color: #111827; }
        .summary, .records { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .summary td, .records th, .records td { padding: 7px; border: 1px solid #d1d5db; vertical-align: top; }
        .records th { background: #f3f4f6; text-align: left; }
        .amount { text-align: right; white-space: nowrap; }
        h1 { margin: 8px 0 2px; font-size: 20px; }
        .muted { color: #6b7280; }
        @media print { .actions { display: none; } body { padding: 0; } @page { size: A4; margin: 12mm; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="actions">
        <button onclick="window.print()">{{ __('finance_uat.print') }}</button>
        <a href="{{ route('dashboard.students.finance.statement.pdf', $student) }}">{{ __('finance_uat.statement_pdf') }}</a>
    </div>
@endunless

@include('pdf.partials.document-header', ['documentTitle' => __('finance_uat.student_statement')])

<h1>{{ $student->full_name }}</h1>
<div class="muted">
    {{ $student->currentEnrollment?->grade?->name ?: '—' }} ·
    {{ $student->currentEnrollment?->schoolClass?->name ?: '—' }} ·
    {{ $student->currentEnrollment?->academicYear?->name ?: '—' }}
</div>

<table class="summary">
    <tr>
        <td>{{ __('finance_uat.charged') }}<br><strong>{{ number_format((float) $summary['invoiced'], 2) }} EGP</strong></td>
        <td>{{ __('finance_uat.paid') }}<br><strong>{{ number_format((float) $summary['paid'], 2) }} EGP</strong></td>
        <td>{{ __('finance_uat.balance') }}<br><strong>{{ number_format((float) $summary['remaining'], 2) }} EGP</strong></td>
        <td>{{ __('finance_uat.overdue') }}<br><strong>{{ number_format((float) $summary['overdue'], 2) }} EGP</strong></td>
    </tr>
</table>

<h2>{{ __('finance_uat.invoices') }}</h2>
<table class="records">
    <thead><tr><th>{{ __('finance_uat.number') }}</th><th>{{ __('finance_uat.date') }}</th><th>{{ __('finance_uat.payment_purpose') }}</th><th class="amount">{{ __('finance_uat.amount') }}</th><th class="amount">{{ __('finance_uat.paid') }}</th><th class="amount">{{ __('finance_uat.balance') }}</th></tr></thead>
    <tbody>
    @forelse($summary['invoices'] as $invoice)
        <tr>
            <td>{{ $invoice->display_number }}</td>
            <td>{{ $invoice->created_at?->format('d.m.Y') }}</td>
            <td>{{ $invoice->items->map(fn ($item) => $item->fee?->name_ru ?: $item->description)->filter()->implode(', ') ?: '—' }}</td>
            <td class="amount">{{ number_format((float) $invoice->total_amount, 2) }}</td>
            <td class="amount">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
            <td class="amount">{{ number_format((float) $invoice->remaining_amount, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="6">{{ __('finance_uat.no_invoices') }}</td></tr>
    @endforelse
    </tbody>
</table>

<h2>{{ __('finance_uat.payments') }}</h2>
<table class="records">
    <thead><tr><th>{{ __('finance_uat.number') }}</th><th>{{ __('finance_uat.date') }}</th><th>{{ __('finance_uat.invoice') }}</th><th>{{ __('finance_uat.payment_method') }}</th><th class="amount">{{ __('finance_uat.amount') }}</th></tr></thead>
    <tbody>
    @forelse($summary['payments'] as $payment)
        <tr>
            <td>{{ $payment->payment_number }}</td>
            <td>{{ ($payment->paid_at ?? $payment->created_at)?->format('d.m.Y H:i') }}</td>
            <td>{{ $payment->invoice?->display_number }}</td>
            <td>{{ $payment->payment_method }}</td>
            <td class="amount">{{ number_format((float) $payment->amount, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">{{ __('finance_uat.no_payments') }}</td></tr>
    @endforelse
    </tbody>
</table>

@include('pdf.partials.document-footer', ['academicYear' => $student->currentEnrollment?->academicYear])
</body>
</html>
