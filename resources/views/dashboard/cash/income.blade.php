@extends('dashboard.layouts.app')

@section('content')

<div class="container">


<h3 class="mb-4">Add Income</h3>

@if(session('success'))
<div class="alert alert-success">
    {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="alert alert-danger">
    {{ session('error') }}
</div>
@endif

<form method="POST" action="{{ route('cash.storeIncome') }}">
@csrf

<div class="row">

<div class="col-md-4">
<label>Cash Account</label>
<select name="cash_account_id" class="form-control">

@foreach($accounts as $account)
<option value="{{ $account->id }}">
{{ $account->name }}
</option>
@endforeach

</select>
</div>

<div class="col-md-4">
<label>Amount</label>
<input type="number" name="amount" class="form-control" min="0" step="0.01" required>
</div>

<div class="col-md-4">
<label>Description</label>
<input type="text" name="notes" class="form-control">
</div>

</div>

<br>

<button class="btn btn-success">
Add Income
</button>

</form>

</div>

@endsection