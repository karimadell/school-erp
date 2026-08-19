@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">🔔 {{ __('bell_schedule.title') }}</h3>
            <div class="text-muted">{{ __('bell_schedule.list_hint') }}</div>
        </div>

        @can('manage timetable')
            <a href="{{ route('dashboard.bell-schedules.create') }}" class="btn btn-primary">
                + {{ __('bell_schedule.create') }}
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
                        <th>{{ __('bell_schedule.fields.name') }}</th>
                        <th>{{ __('bell_schedule.fields.academic_year') }}</th>
                        <th>{{ __('bell_schedule.fields.shift') }}</th>
                        <th>{{ __('bell_schedule.fields.periods') }}</th>
                        <th>{{ __('bell_schedule.fields.is_default') }}</th>
                        <th>{{ __('bell_schedule.fields.is_active') }}</th>
                        <th class="text-end">{{ __('bell_schedule.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($bellSchedules as $schedule)
                    <tr>
                        <td>{{ $schedule->name }}</td>
                        <td>{{ $schedule->academicYear?->name }}</td>
                        <td>{{ $schedule->shift }}</td>
                        <td>{{ $schedule->periods_count }}</td>
                        <td>
                            @if($schedule->is_default)
                                <span class="badge bg-success">{{ __('bell_schedule.fields.is_default') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $schedule->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $schedule->is_active ? __('classroom.active') : __('classroom.inactive') }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('manage timetable')
                                <a href="{{ route('dashboard.bell-schedules.edit', $schedule) }}" class="btn btn-sm btn-warning">
                                    {{ __('bell_schedule.edit') }}
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">{{ __('bell_schedule.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $bellSchedules->links() }}</div>
</div>
@endsection
