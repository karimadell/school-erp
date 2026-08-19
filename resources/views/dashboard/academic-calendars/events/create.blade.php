@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <a href="{{ route('dashboard.academic-calendars.edit', $academicCalendar) }}" class="text-decoration-none small text-muted d-block mb-2">
        &larr; {{ __('academic_calendar.events.back') }}
    </a>
    <h3 class="fw-bold mb-4">{{ __('academic_calendar.events.create') }}</h3>
    <form method="POST" action="{{ route('dashboard.academic-calendars.events.store', $academicCalendar) }}">
        @csrf
        @include('dashboard.academic-calendars.events._form')
    </form>
</div>
@endsection
