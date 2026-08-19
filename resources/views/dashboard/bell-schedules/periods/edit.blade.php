@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <a href="{{ route('dashboard.bell-schedules.edit', $bellSchedule) }}" class="text-decoration-none small text-muted d-block mb-2">
        &larr; {{ __('bell_schedule.periods.back') }}
    </a>
    <h3 class="fw-bold mb-4">{{ __('bell_schedule.periods.edit') }}</h3>
    <form method="POST" action="{{ route('dashboard.bell-schedules.periods.update', [$bellSchedule, $period]) }}">
        @csrf
        @method('PUT')
        @include('dashboard.bell-schedules.periods._form')
    </form>
</div>
@endsection
