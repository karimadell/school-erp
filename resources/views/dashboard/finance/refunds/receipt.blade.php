@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
<h1 class="h3 mb-0">{{ __('invoices.refund_receipt') }}</h1>
<a href="{{ route('dashboard.invoices.show',$refund->invoice) }}" class="btn btn-outline-secondary">{{ __('invoices.back') }}</a>
</div>
<div class="card border-0 shadow-sm"><div class="card-body">
<dl class="row mb-0">
<dt class="col-sm-4">{{ __('invoices.refund_number') }}</dt><dd class="col-sm-8">{{ $refund->display_number }}</dd>
<dt class="col-sm-4">{{ __('invoices.refund_date') }}</dt><dd class="col-sm-8">{{ ($refund->refunded_at ?? $refund->created_at)?->format('d.m.Y H:i') }}</dd>
<dt class="col-sm-4">{{ __('invoices.original_payment') }}</dt><dd class="col-sm-8">{{ $refund->originalPayment?->payment_number }}</dd>
<dt class="col-sm-4">{{ __('invoices.invoice_number') }}</dt><dd class="col-sm-8">{{ $refund->invoice?->display_number }}</dd>
<dt class="col-sm-4">{{ __('invoices.student') }}</dt><dd class="col-sm-8">{{ $refund->invoice?->student?->full_name }}</dd>
<dt class="col-sm-4">{{ __('invoices.refund_amount') }}</dt><dd class="col-sm-8"><strong>{{ $refund->amount }} {{ $refund->currency }}</strong></dd>
<dt class="col-sm-4">{{ __('invoices.cash_account') }}</dt><dd class="col-sm-8">{{ $refund->cashAccount?->name ?: '—' }}</dd>
<dt class="col-sm-4">{{ __('invoices.refund_reason') }}</dt><dd class="col-sm-8">{{ $refund->reason }}</dd>
<dt class="col-sm-4">{{ __('invoices.refund_executor') }}</dt><dd class="col-sm-8">{{ $refund->executor?->name ?: '—' }}</dd>
</dl>
</div></div>
</div>
@endsection
