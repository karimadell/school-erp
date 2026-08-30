@extends('layouts.dashboard')
@section('content')
<div class="container py-4"><h1 class="h3 mb-4">{{ __('invoices.create_refund') }}</h1>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h2 class="h5">{{ __('invoices.original_payment') }}: {{ $payment->payment_number }}</h2>
<div>{{ $payment->invoice?->display_number }} · {{ $payment->invoice?->student?->full_name }}</div>
<div class="row g-2 mt-3">
<div class="col-md-4">{{ __('invoices.amount') }}: <strong>{{ $payment->amount }} EGP</strong></div>
<div class="col-md-4">{{ __('invoices.refunded') }}: <strong>{{ $payment->refundedAmount() }} EGP</strong></div>
<div class="col-md-4">{{ __('invoices.refundable') }}: <strong>{{ $refundable }} EGP</strong></div>
</div></div></div>
@if(($refundLines ?? collect())->isNotEmpty())
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h2 class="h6 mb-3">{{ __('invoices.refund_lines_heading') }}</h2>
<div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>{{ __('invoices.refund_line_service') }}</th><th class="text-end">{{ __('invoices.refund_line_allocated') }}</th><th class="text-end">{{ __('invoices.refund_line_refunded') }}</th><th class="text-end">{{ __('invoices.refund_line_remaining') }}</th><th class="text-end">{{ __('invoices.refund_amount') }}</th></tr></thead>
<tbody>
@foreach($refundLines as $line)
<tr>
<td>{{ $line['label'] }}@if($line['non_refundable'])<span class="badge bg-secondary ms-2">{{ __('invoices.refund_line_non_refundable') }}</span>@endif</td>
<td class="text-end">{{ $line['allocated'] }} EGP</td>
<td class="text-end">{{ $line['refunded'] }} EGP</td>
<td class="text-end">{{ $line['remaining'] }} EGP</td>
<td class="text-end">
@if(! $line['non_refundable'] && bccomp($line['remaining'], '0.00', 2) > 0)
<input type="number" step="0.01" min="0" max="{{ $line['remaining'] }}" name="allocations[{{ $line['id'] }}]" value="{{ old('allocations.'.$line['id']) }}" class="form-control form-control-sm" form="refund-form">
@else
—
@endif
</td>
</tr>
@endforeach
</tbody>
</table></div>
<div class="small text-muted mt-2">{{ __('invoices.refund_split_hint') }}</div>
@error('allocations')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div></div>
@endif
<form id="refund-form" method="POST" action="{{ route('dashboard.payments.refund.store',$payment) }}" class="card border-0 shadow-sm"><div class="card-body row g-3">@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
<div class="col-md-6"><label class="form-label" for="amount">{{ __('invoices.refund_amount') }}, EGP <span class="text-danger" aria-hidden="true">*</span></label>
<input id="amount" type="number" step="0.01" min="0.01" max="{{ $refundable }}" name="amount" value="{{ old('amount',$refundable) }}" class="form-control @error('amount') is-invalid @enderror" required>
@error('amount')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
<div class="col-12"><label class="form-label" for="reason">{{ __('invoices.refund_reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
<input id="reason" type="text" name="reason" maxlength="500" value="{{ old('reason') }}" class="form-control @error('reason') is-invalid @enderror" required>
@error('reason')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
<div class="col-12"><div class="small text-muted mb-3">Возврат будет выполнен из кассы исходного платежа: {{ $payment->cashAccount?->name ?: '—' }}.</div>
<button class="btn btn-danger">{{ __('invoices.refund_submit') }}</button>
<a href="{{ route('dashboard.invoices.show',$payment->invoice) }}" class="btn btn-outline-secondary">{{ __('invoices.back') }}</a></div>
</div></form></div>
@endsection
