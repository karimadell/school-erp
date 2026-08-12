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
<form method="POST" action="{{ route('dashboard.payments.refund.store',$payment) }}" class="card border-0 shadow-sm"><div class="card-body row g-3">@csrf
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
