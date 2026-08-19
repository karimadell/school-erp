@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">📅 {{ __('academic_calendar.edit') }} — {{ $academicCalendar->academicYear?->name }}</h3>

    <form method="POST" action="{{ route('dashboard.academic-calendars.update', $academicCalendar) }}">
        @csrf
        @method('PUT')
        @include('dashboard.academic-calendars._form')
    </form>

    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header fw-bold bg-light d-flex justify-content-between align-items-center">
            <span>{{ __('academic_calendar.events.title') }}</span>
            @can('manage timetable')
                <a href="{{ route('dashboard.academic-calendars.events.create', $academicCalendar) }}" class="btn btn-sm btn-primary">
                    + {{ __('academic_calendar.events.create') }}
                </a>
            @endcan
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('academic_calendar.fields.name') }}</th>
                        <th>{{ __('academic_calendar.fields.type') }}</th>
                        <th>{{ __('academic_calendar.fields.start_date') }}</th>
                        <th>{{ __('academic_calendar.fields.end_date') }}</th>
                        <th>{{ __('academic_calendar.fields.is_active') }}</th>
                        <th class="text-end">{{ __('academic_calendar.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($events as $event)
                    <tr>
                        <td>{{ $event->name }}</td>
                        <td><span class="badge bg-info text-dark">{{ __("academic_calendar.types.{$event->type}") }}</span></td>
                        <td>{{ $event->start_date?->format('d.m.Y') }}</td>
                        <td>{{ $event->end_date?->format('d.m.Y') }}</td>
                        <td>
                            <span class="badge {{ $event->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $event->is_active ? __('classroom.active') : __('classroom.inactive') }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('manage timetable')
                                <a href="{{ route('dashboard.academic-calendars.events.edit', [$academicCalendar, $event]) }}" class="btn btn-sm btn-warning">
                                    {{ __('academic_calendar.events.edit') }}
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('academic_calendar.events.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
