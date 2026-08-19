@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">🔔 {{ __('bell_schedule.create') }}</h3>
    <form method="POST" action="{{ route('dashboard.bell-schedules.store') }}">
        @csrf
        @include('dashboard.bell-schedules._form')
    </form>
</div>
@endsection
