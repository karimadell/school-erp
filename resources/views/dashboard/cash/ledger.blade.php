@extends('dashboard.layouts.app')

@section('content')

<div class="container">

<h3 class="mb-4">Cash Ledger</h3>

{{-- Filters --}}
<div class="card mb-3">
<div class="card-body">

<form method="GET">

<div class="row">

<div class="col-md-3">
<label>From Date</label>
<input type="date" name="from" class="form-control" value="{{ request('from') }}">
</div>

<div class="col-md-3">
<label>To Date</label>
<input type="date" name="to" class="form-control" value="{{ request('to') }}">
</div>

<div class="col-md-3">
<label>Account</label>

<select name="account_id" class="form-control">

<option value="">All Accounts</option>

@foreach(\App\Models\CashAccount::all() as $account)

<option value="{{ $account->id }}"
{{ request('account_id') == $account->id ? 'selected' : '' }}>

{{ $account->name }}

</option>

@endforeach

</select>

</div>

<div class="col-md-3 d-flex align-items-end">

<button class="btn btn-primary w-100">
Filter
</button>

</div>

</div>

</form>

</div>
</div>


{{-- Ledger Table --}}

<div class="card">
<div class="card-body">

<table class="table table-bordered table-striped">

<thead>

<tr>

<th>#</th>
<th>Date</th>
<th>Account</th>
<th>Type</th>
<th>Amount</th>
<th>Description</th>

</tr>

</thead>

<tbody>

@php
$totalIncome = 0;
$totalExpense = 0;
@endphp

@foreach($transactions as $trx)

<tr>

<td>{{ $loop->iteration }}</td>

<td>{{ $trx->created_at->format('Y-m-d H:i') }}</td>

<td>{{ $trx->account->name ?? '-' }}</td>

<td>

@if($trx->type == 'in')

<span class="badge bg-success">
Income
</span>

@php $totalIncome += $trx->amount; @endphp

@else

<span class="badge bg-danger">
Expense
</span>

@php $totalExpense += $trx->amount; @endphp

@endif

</td>

<td>{{ number_format($trx->amount,2) }}</td>

<td>{{ $trx->notes }}</td>

</tr>

@endforeach

</tbody>

</table>

</div>
</div>


{{-- Totals --}}

<div class="row mt-3">

<div class="col-md-4">

<div class="alert alert-success">
Total Income: {{ number_format($totalIncome,2) }}
</div>

</div>

<div class="col-md-4">

<div class="alert alert-danger">
Total Expense: {{ number_format($totalExpense,2) }}
</div>

</div>

<div class="col-md-4">

<div class="alert alert-primary">
Balance: {{ number_format($totalIncome - $totalExpense,2) }}
</div>

</div>

</div>


{{-- Pagination --}}

<div class="mt-3">

{{ $transactions->links() }}

</div>


</div>

@endsection