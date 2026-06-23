@extends('layouts.dashboard')

@section('content')

<div class="container">

<h3 class="mb-4">🍽 {{ __('menu.restaurant_report') }}</h3>

<form class="row mb-4">

<div class="col-md-3">
<input type="date" name="date" value="{{ $date }}" class="form-control">
</div>

<div class="col-md-2">
<button class="btn btn-primary">
{{ __('menu.search') }}
</button>
</div>

</form>


<div class="row mb-4">

<div class="col-md-6">

<div class="card bg-success text-white">

<div class="card-body">

<h5>Paid Students</h5>

<h2>{{ $paidCount }}</h2>

</div>

</div>

</div>


<div class="col-md-6">

<div class="card bg-danger text-white">

<div class="card-body">

<h5>Unpaid Students</h5>

<h2>{{ $unpaidCount }}</h2>

</div>

</div>

</div>

</div>


<div class="card">

<div class="card-header">
{{ __('menu.students') }}
</div>

<div class="card-body">

<table class="table table-bordered">

<thead>

<tr>
<th>#</th>
<th>{{ __('menu.students') }}</th>
<th>{{ __('menu.date') }}</th>
<th>{{ __('menu.status') }}</th>
</tr>

</thead>

<tbody>

@forelse($students as $s)

<tr>

<td>{{ $loop->iteration }}</td>

<td>{{ $s->student->name }}</td>

<td>{{ $s->due_date }}</td>

<td>

@if($s->status == 'paid')

<span class="badge bg-success">
Paid
</span>

@else

<span class="badge bg-danger">
Unpaid
</span>

@endif

</td>

</tr>

@empty

<tr>

<td colspan="4" class="text-center">

{{ __('menu.no_records_found') }}

</td>

</tr>

@endforelse

</tbody>

</table>

</div>

</div>

</div>

@endsection