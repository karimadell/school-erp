@extends('layouts.dashboard')

@section('content')

<style>

@media print{
    .no-print{
        display:none;
    }

    body{
        background:#fff;
    }
}

.report-title{
    font-size:26px;
    font-weight:700;
}

.summary{
    font-size:18px;
    font-weight:600;
}

</style>

<div class="container">

<div class="no-print mb-4">

<h3 class="mb-3">🍽 Restaurant Weekly Report</h3>

<form class="row g-2">

<div class="col-md-3">
<label>Start Date</label>
<input type="date" name="start" value="{{ $start }}" class="form-control">
</div>

<div class="col-md-3">
<label>End Date</label>
<input type="date" name="end" value="{{ $end }}" class="form-control">
</div>

<div class="col-md-2 d-flex align-items-end">
<button class="btn btn-primary w-100">
Search
</button>
</div>

<div class="col-md-2 d-flex align-items-end">
<button onclick="window.print()" type="button" class="btn btn-success w-100">
🖨 Print
</button>
</div>

</form>

</div>


<div class="text-center mb-4">

<div class="report-title">
Restaurant Weekly Meal Plan
</div>

<div>
From {{ $start }} → {{ $end }}
</div>

</div>


<div class="card">

<div class="card-body">

<table class="table table-bordered table-striped">

<thead>

<tr>

<th style="width:120px">Date</th>
<th style="width:150px">Meals Count</th>

</tr>

</thead>

<tbody>

@forelse($data as $day)

<tr>

<td>
{{ $day->day }}
</td>

<td class="fw-bold">
{{ $day->total }}
</td>

</tr>

@empty

<tr>

<td colspan="2" class="text-center">
No meals registered
</td>

</tr>

@endforelse

</tbody>

</table>

</div>

</div>


<div class="mt-4 summary">

Total Meals This Week:
{{ $data->sum('total') }}

</div>

</div>

@endsection