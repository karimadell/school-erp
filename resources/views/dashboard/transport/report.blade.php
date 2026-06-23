@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3 class="mb-4">🚌 Transport Report</h3>

<button onclick="window.print()" class="btn btn-dark mb-3">
🖨 Print
</button>

@foreach($routes as $route)

<div class="card mb-4">
<div class="card-body">

<h5>
🚌 {{ $route->name }} 
| Driver: {{ $route->driver_name ?? '-' }}
| Bus: {{ $route->bus_number ?? '-' }}
</h5>

<p>
👨‍🎓 Students: {{ $route->students->count() }} /
{{ $route->capacity }}
</p>

@php
$total = $route->students->sum('price');
@endphp

<p class="fw-bold text-success">
💰 Total Income: {{ number_format($total,2) }}
</p>

<table class="table table-bordered">

<thead>
<tr>
<th>#</th>
<th>Student</th>
<th>Price</th>
</tr>
</thead>

<tbody>

@foreach($route->students as $i => $sub)

<tr>
<td>{{ $i+1 }}</td>
<td>{{ $sub->student->name ?? '-' }}</td>
<td>{{ number_format($sub->price,2) }}</td>
</tr>

@endforeach

</tbody>

</table>

</div>
</div>

@endforeach

</div>

@endsection