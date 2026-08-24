<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>{{ __('teacher_salary.payslip') }} — {{ $salary->employee_display_name }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;color:#111827;font-size:13px}.toolbar{margin-bottom:16px}.meta,.salary{width:100%;border-collapse:collapse;margin-top:18px}.meta td,.salary th,.salary td{border:1px solid #d1d5db;padding:9px}.salary th{background:#f3f4f6;text-align:left}.money{text-align:right}.net{font-weight:700}.status{margin-top:18px}@media print{.toolbar{display:none}}</style></head><body>
@unless($pdf)<div class="toolbar"><button onclick="window.print()">{{ __('teacher_salary.print') }}</button> <a href="{{ route('dashboard.teacher-salaries.pdf', $salary) }}">{{ __('teacher_salary.pdf') }}</a></div>@endunless
@include('pdf.partials.document-header', ['documentTitle' => __('teacher_salary.payslip')])
<table class="meta">
<tr><td><strong>{{ __('teacher_salary.employee') }}</strong></td><td>{{ $salary->employee_display_name }}</td></tr>
<tr><td><strong>{{ __('teacher_salary.position') }}</strong></td><td>{{ $salary->position ?: '—' }}</td></tr>
<tr><td><strong>{{ __('teacher_salary.period') }}</strong></td><td>{{ $salary->salary_month?->translatedFormat('F Y') ?: '—' }}</td></tr>
<tr><td><strong>{{ __('teacher_salary.generated_at') }}</strong></td><td>{{ now()->format('d.m.Y H:i') }}</td></tr>
@if($salary->paid_at)<tr><td><strong>{{ __('teacher_salary.paid_at') }}</strong></td><td>{{ $salary->paid_at->format('d.m.Y H:i') }}</td></tr>@endif
</table>
<table class="salary"><thead><tr><th>{{ __('teacher_salary.component') }}</th><th>{{ __('teacher_salary.reason') }}</th><th class="money">{{ __('teacher_salary.amount') }}, EGP</th></tr></thead><tbody>
<tr><td>{{ __('teacher_salary.base_salary') }}</td><td>—</td><td class="money">{{ number_format((float)$salary->base_salary,2) }}</td></tr>
@foreach($salary->adjustments as $line)<tr><td>{{ __('teacher_salary.types.'.$line->type) }}</td><td>{{ $line->reason }}</td><td class="money">{{ $line->type === 'deduction' ? '− ' : '+ ' }}{{ number_format((float)$line->amount,2) }}</td></tr>@endforeach
@if($salary->adjustments->isEmpty() && bccomp((string)$salary->bonus,'0.00',2)>0)<tr><td>{{ __('teacher_salary.bonus') }}</td><td>{{ __('teacher_salary.legacy_amount') }}</td><td class="money">+ {{ number_format((float)$salary->bonus,2) }}</td></tr>@endif
@if($salary->adjustments->isEmpty() && bccomp((string)$salary->deductions,'0.00',2)>0)<tr><td>{{ __('teacher_salary.deduction') }}</td><td>{{ __('teacher_salary.legacy_amount') }}</td><td class="money">− {{ number_format((float)$salary->deductions,2) }}</td></tr>@endif
<tr class="net"><td colspan="2">{{ __('teacher_salary.net_salary') }}</td><td class="money">{{ number_format((float)$salary->net_salary,2) }}</td></tr>
</tbody></table>
<div class="status"><strong>{{ __('teacher_salary.status') }}:</strong> {{ __('teacher_salary.statuses.'.$salary->status) }}</div>
</body></html>
