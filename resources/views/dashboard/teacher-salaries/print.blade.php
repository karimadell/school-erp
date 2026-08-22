<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('teacher_salary.payslip') }} — {{ $salary->teacher?->full_name }}</title>
    <style>
        body { margin: 0; padding: 24px; color: #111827; font: 12px DejaVu Sans, sans-serif; }
        .actions { margin-bottom: 18px; text-align: right; }
        .actions a, .actions button { padding: 8px 12px; border: 1px solid #9ca3af; background: #fff; color: #111827; text-decoration: none; }
        .meta, .salary { width: 100%; border-collapse: collapse; margin-top: 18px; }
        .meta td, .salary th, .salary td { border: 1px solid #d1d5db; padding: 9px; }
        .salary th { background: #f3f4f6; text-align: left; }
        .money { text-align: right; }
        .net td { font-size: 15px; font-weight: bold; border-top: 2px solid #111827; }
        .status { margin-top: 16px; padding: 10px; border: 1px solid #d1d5db; }
        @media print { .actions { display: none; } body { padding: 0; } @page { size: A4; margin: 12mm; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="actions">
        <button onclick="window.print()">{{ __('teacher_salary.print') }}</button>
        <a href="{{ route('dashboard.teacher-salaries.pdf', $salary) }}">{{ __('teacher_salary.pdf') }}</a>
    </div>
@endunless

@include('pdf.partials.document-header', ['documentTitle' => __('teacher_salary.payslip')])

<table class="meta">
    <tr><td><strong>{{ __('teacher_salary.teacher') }}</strong></td><td>{{ $salary->teacher?->full_name ?: '—' }}</td></tr>
    <tr><td><strong>{{ __('teacher_salary.period') }}</strong></td><td>{{ $salary->salary_month?->translatedFormat('F Y') ?: '—' }}</td></tr>
    <tr><td><strong>{{ __('teacher_salary.generated_at') }}</strong></td><td>{{ now()->format('d.m.Y H:i') }}</td></tr>
</table>

<table class="salary">
    <thead><tr><th>{{ __('teacher_salary.component') }}</th><th class="money">{{ __('teacher_salary.amount') }}, EGP</th></tr></thead>
    <tbody>
        <tr><td>{{ __('teacher_salary.base_salary') }}</td><td class="money">{{ number_format((float) $salary->base_salary, 2) }}</td></tr>
        <tr><td>{{ __('teacher_salary.bonus') }}</td><td class="money">{{ number_format((float) $salary->bonus, 2) }}</td></tr>
        <tr><td>{{ __('teacher_salary.deductions') }}</td><td class="money">− {{ number_format((float) $salary->deductions, 2) }}</td></tr>
        <tr class="net"><td>{{ __('teacher_salary.net_salary') }}</td><td class="money">{{ number_format((float) $salary->net_salary, 2) }}</td></tr>
    </tbody>
</table>

<div class="status"><strong>{{ __('teacher_salary.status') }}:</strong> {{ __('teacher_salary.accrued') }}</div>

@include('pdf.partials.document-footer')
</body>
</html>
