@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">📅 {{ __('academic_calendar.title') }}</h3>
            <div class="text-muted">{{ __('academic_calendar.list_hint') }}</div>
        </div>

        @can('manage timetable')
            <a href="{{ route('dashboard.academic-calendars.create') }}" class="btn btn-primary">
                + {{ __('academic_calendar.create') }}
            </a>
        @endcan
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('academic_calendar.fields.academic_year') }}</th>
                        <th>{{ __('academic_calendar.fields.weekly_days_off') }}</th>
                        <th>{{ __('academic_calendar.fields.events') }}</th>
                        <th>{{ __('academic_calendar.fields.updated_at') }}</th>
                        <th class="text-end">{{ __('academic_calendar.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($academicCalendars as $calendar)
                    <tr>
                        <td>{{ $calendar->academicYear?->name }}</td>
                        <td>{{ collect($calendar->weekly_days_off ?? [])->map(fn ($day) => __("academic_calendar.weekdays.$day"))->implode(', ') }}</td>
                        <td>{{ $calendar->events_count }}</td>
                        <td>{{ $calendar->updated_at?->format('d.m.Y H:i') }}</td>
                        <td class="text-end">
                            @can('manage timetable')
                                <a href="{{ route('dashboard.academic-calendars.edit', $calendar) }}" class="btn btn-sm btn-warning">
                                    {{ __('academic_calendar.edit') }}
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ __('academic_calendar.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $academicCalendars->links() }}</div>
</div>
@endsection
