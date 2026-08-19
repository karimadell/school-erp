@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">📅 {{ __('academic_calendar.create') }}</h3>

    @if($academicYears->isEmpty())
        <div class="alert alert-warning">{{ __('academic_calendar.no_active_year') }}</div>
    @else
        <form method="POST" action="{{ route('dashboard.academic-calendars.store') }}">
            @csrf
            @include('dashboard.academic-calendars._form')
        </form>
    @endif
</div>
@endsection
