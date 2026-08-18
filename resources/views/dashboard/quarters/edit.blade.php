@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">{{ __('quarters.edit') }}</h3>
    <form method="POST" action="{{ route('dashboard.academic-years.quarters.update', [$academicYear, $quarter]) }}">
        @csrf @method('PUT')
        @include('dashboard.quarters._form')
    </form>
</div>
@endsection
