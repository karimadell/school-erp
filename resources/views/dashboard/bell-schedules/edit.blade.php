@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">🔔 {{ __('bell_schedule.edit') }} — {{ $bellSchedule->name }}</h3>

    <form method="POST" action="{{ route('dashboard.bell-schedules.update', $bellSchedule) }}">
        @csrf
        @method('PUT')
        @include('dashboard.bell-schedules._form')
    </form>

    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header fw-bold bg-light d-flex justify-content-between align-items-center">
            <span>{{ __('bell_schedule.periods.title') }}</span>
            @can('manage timetable')
                <a href="{{ route('dashboard.bell-schedules.periods.create', $bellSchedule) }}" class="btn btn-sm btn-primary">
                    + {{ __('bell_schedule.periods.create') }}
                </a>
            @endcan
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('bell_schedule.fields.period_number') }}</th>
                        <th>{{ __('bell_schedule.fields.label') }}</th>
                        <th>{{ __('bell_schedule.fields.starts_at') }}</th>
                        <th>{{ __('bell_schedule.fields.ends_at') }}</th>
                        <th>{{ __('bell_schedule.fields.break_after_minutes') }}</th>
                        <th>{{ __('bell_schedule.fields.is_active') }}</th>
                        <th class="text-end">{{ __('bell_schedule.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($periods as $period)
                    <tr>
                        <td>{{ $period->period_number }}</td>
                        <td>{{ $period->label }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($period->starts_at)->format('H:i') }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($period->ends_at)->format('H:i') }}</td>
                        <td>{{ $period->break_after_minutes }}</td>
                        <td>
                            <span class="badge {{ $period->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $period->is_active ? __('classroom.active') : __('classroom.inactive') }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('manage timetable')
                                <a href="{{ route('dashboard.bell-schedules.periods.edit', [$bellSchedule, $period]) }}" class="btn btn-sm btn-warning">
                                    {{ __('bell_schedule.periods.edit') }}
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">{{ __('bell_schedule.periods.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
