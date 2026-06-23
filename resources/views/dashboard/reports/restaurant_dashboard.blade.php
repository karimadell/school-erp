@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3>🍽 Kitchen Dashboard</h3>

<div class="row mb-4">

<div class="col-md-3">
<div class="card text-center bg-success text-white p-3">
<h5>Today Meals</h5>
<h2>{{ $today }}</h2>
</div>
</div>

<div class="col-md-3">
<div class="card text-center bg-primary text-white p-3">
<h5>This Week</h5>
<h2>{{ $week }}</h2>
</div>
</div>

</div>

<a href="{{ route('dashboard.reports.restaurant.kitchen') }}" class="btn btn-dark">
👨‍🍳 Kitchen List
</a>

<a href="{{ route('dashboard.reports.restaurant.kitchen.pdf') }}" class="btn btn-danger">
🧾 Print PDF
</a>

</div>

@endsection