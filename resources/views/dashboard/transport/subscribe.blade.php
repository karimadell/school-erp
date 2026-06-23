@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3 class="mb-4">🚌 {{ __('Transport Subscription') }}</h3>

{{-- Success Message --}}
@if(session('success'))
<div class="alert alert-success">
    {{ session('success') }}
</div>
@endif

{{-- Errors --}}
@if ($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="card">
<div class="card-body">

<form method="POST" action="{{ route('dashboard.transport.subscribe') }}">
@csrf

<div class="row">

{{-- Student --}}
<div class="col-md-4 mb-3">
<label class="form-label">👨‍🎓 Student</label>
<select name="student_id" class="form-control" required>

<option value="">Select Student</option>

@foreach($students as $s)
<option value="{{ $s->id }}">
    {{ $s->name }}
</option>
@endforeach

</select>
</div>

{{-- Route --}}
<div class="col-md-4 mb-3">
<label class="form-label">🚌 Route</label>
<select name="route_id" class="form-control" required>

<option value="">Select Route</option>

@foreach($routes as $r)
<option value="{{ $r->id }}">
    {{ $r->name }} ({{ $r->driver_name }})
</option>
@endforeach

</select>
</div>

{{-- Price --}}
<div class="col-md-4 mb-3">
<label class="form-label">💰 Price</label>
<input type="number" step="0.01" name="price" class="form-control" required>
</div>

</div>

<button class="btn btn-success">
✅ Subscribe Student
</button>

</form>

</div>
</div>

</div>

@endsection