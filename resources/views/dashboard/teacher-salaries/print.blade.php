<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>{{ __('teacher_salary.payslip') }} — {{ $salary->employee_display_name }}</title>
<style>
/*
    Compact A5 receipt. Tables only (no flex/grid): this template is
    shared verbatim between the browser print view and dompdf's PDF
    render (TeacherSalaryPrintController::pdf()), and dompdf's flex/grid
    support is unreliable — the same reason the pre-existing meta/salary
    tables below were never flex/grid to begin with.

    The shared pdf.partials.document-header component is reused as-is
    (it's included on invoices/receipts too) and only visually shrunk
    here via higher-specificity "body.payslip …" selectors, so nothing
    about the shared partial itself changes for other documents.
*/
body{font-family:"DejaVu Sans",sans-serif;color:#111827;font-size:10.5px;margin:0;padding:6mm 8mm}
@page{size:A5 portrait;margin:8mm 10mm}
.toolbar{margin-bottom:10px}
.toolbar button,.toolbar a{font-size:12px;padding:5px 10px;margin-right:6px}
@media print{.toolbar{display:none}}

body.payslip .school-document-header{margin-bottom:6px;padding-bottom:6px}
body.payslip .school-document-logo{max-width:56px;max-height:50px}
body.payslip .school-document-logo-cell{width:64px}
body.payslip .school-document-identity{padding-right:64px}
body.payslip .school-document-name{font-size:13px}
body.payslip .school-document-subtitle{font-size:8.5px;margin-top:1px}
body.payslip .school-document-title{font-size:11.5px;margin-top:4px}

.meta,.salary{width:100%;border-collapse:collapse}
.meta{margin-top:6px;margin-bottom:8px}
.meta td{border:1px solid #d1d5db;padding:4px 6px;font-size:10px}
.meta td.label{color:#6b7280;width:24%}
.salary{margin-top:0}
.salary th,.salary td{border:1px solid #d1d5db;padding:4px 6px;font-size:10px}
.salary th{background:#f3f4f6;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.02em}
.money{text-align:right;white-space:nowrap}
.net td{font-weight:700;font-size:12px;background:#f3f4f6}

.status{margin:8px 0 0;font-size:11px}
.status-value{font-weight:700}
.status-draft{color:#6b7280}
.status-approved{color:#1d4ed8}
.status-paid{color:#15803d}

.signatures{width:100%;border-collapse:collapse;margin-top:22px}
.signatures td{vertical-align:top;padding:0 6px;font-size:10px;width:50%}
.signatures td:first-child{padding-left:0}
.signatures td:last-child{padding-right:0}
.sig-line{margin-top:16px}
.sig-line:first-child{margin-top:0}
</style>
</head><body class="payslip">
@unless($pdf)<div class="toolbar"><button onclick="window.print()">{{ __('teacher_salary.print') }}</button> <a href="{{ route('dashboard.teacher-salaries.pdf', $salary) }}">{{ __('teacher_salary.pdf') }}</a></div>@endunless
@include('pdf.partials.document-header', ['documentTitle' => __('teacher_salary.payslip')])
<table class="meta">
<tr><td class="label"><strong>{{ __('teacher_salary.employee') }}</strong></td><td>{{ $salary->employee_display_name }}</td><td class="label"><strong>{{ __('teacher_salary.position') }}</strong></td><td>{{ $salary->position ?: '—' }}</td></tr>
<tr><td class="label"><strong>{{ __('teacher_salary.period') }}</strong></td><td>{{ $salary->salary_month?->translatedFormat('F Y') ?: '—' }}</td><td class="label"><strong>{{ __('teacher_salary.generated_at') }}</strong></td><td>{{ now()->format('d.m.Y H:i') }}</td></tr>
@if($salary->paid_at)<tr><td class="label"><strong>{{ __('teacher_salary.paid_at') }}</strong></td><td colspan="3">{{ $salary->paid_at->format('d.m.Y H:i') }}</td></tr>@endif
</table>
<table class="salary"><thead><tr><th>{{ __('teacher_salary.component') }}</th><th>{{ __('teacher_salary.reason') }}</th><th class="money">{{ __('teacher_salary.amount') }}, EGP</th></tr></thead><tbody>
<tr><td>{{ __('teacher_salary.base_salary') }}</td><td>—</td><td class="money">{{ number_format((float)$salary->base_salary,2) }}</td></tr>
@foreach($salary->adjustments as $line)<tr><td>{{ __('teacher_salary.types.'.$line->type) }}</td><td>{{ $line->reason }}</td><td class="money">{{ $line->type === 'deduction' ? '− ' : '+ ' }}{{ number_format((float)$line->amount,2) }}</td></tr>@endforeach
@if($salary->adjustments->isEmpty() && bccomp((string)$salary->bonus,'0.00',2)>0)<tr><td>{{ __('teacher_salary.bonus') }}</td><td>{{ __('teacher_salary.legacy_amount') }}</td><td class="money">+ {{ number_format((float)$salary->bonus,2) }}</td></tr>@endif
@if($salary->adjustments->isEmpty() && bccomp((string)$salary->allowances,'0.00',2)>0)<tr><td>{{ __('teacher_salary.allowance') }}</td><td>{{ __('teacher_salary.legacy_amount') }}</td><td class="money">+ {{ number_format((float)$salary->allowances,2) }}</td></tr>@endif
@if($salary->adjustments->isEmpty() && bccomp((string)$salary->deductions,'0.00',2)>0)<tr><td>{{ __('teacher_salary.deduction') }}</td><td>{{ __('teacher_salary.legacy_amount') }}</td><td class="money">− {{ number_format((float)$salary->deductions,2) }}</td></tr>@endif
<tr class="net"><td colspan="2">{{ __('teacher_salary.net_salary') }}</td><td class="money">{{ number_format((float)$salary->net_salary,2) }}</td></tr>
</tbody></table>
<div class="status"><strong>{{ __('teacher_salary.status') }}:</strong> <span class="status-value status-{{ $salary->status }}">{{ __('teacher_salary.statuses.'.$salary->status) }}</span></div>
<table class="signatures"><tr>
<td>
<div class="sig-line">{{ __('teacher_salary.received_by') }}: ______________________________</div>
<div class="sig-line">{{ __('teacher_salary.employee_signature') }}: ______________________</div>
<div class="sig-line">{{ __('teacher_salary.received_date') }}: ____ / ____ / ______</div>
</td>
<td>
<div class="sig-line">{{ __('teacher_salary.cashier_signature') }}: ______________________</div>
@if($salary->payer)<div class="sig-line">{{ $salary->payer->name }}</div>@endif
</td>
</tr></table>
</body></html>
