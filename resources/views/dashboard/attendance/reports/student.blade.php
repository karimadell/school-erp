@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <h3 class="fw-bold mb-4">
        📊 {{ __('attendance.student_report') }}
    </h3>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('dashboard.attendance.reports.student') }}" class="row g-2">

                <div class="col-md-4">
                    <select name="student_id" class="form-select">
                        <option value="">{{ __('attendance.select_options') }}</option>

                        @foreach($students as $s)
                            <option value="{{ $s->id }}" @selected(request('student_id') == $s->id)>
                                {{ $s->name ?? $s->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <input type="date" name="from" value="{{ request('from') }}" class="form-control">
                </div>

                <div class="col-md-2">
                    <input type="date" name="to" value="{{ request('to') }}" class="form-control">
                </div>

                <div class="col-md-4">
                    <button class="btn btn-primary w-100">
                        🔍 {{ __('attendance.filter') }}
                    </button>
                </div>

            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">{{ __('attendance.records') }}</div>
                    <h5>{{ $attendances->count() }}</h5>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">✅ {{ __('attendance.present') }}</div>
                    <h5>{{ $stats['present'] }}</h5>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">❌ {{ __('attendance.absent') }}</div>
                    <h5>{{ $stats['absent'] }}</h5>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">⏰ {{ __('attendance.late') }}</div>
                    <h5>{{ $stats['late'] }}</h5>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">📝 {{ __('attendance.excused') }}</div>
                    <h5>{{ $stats['excused'] }}</h5>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">{{ __('attendance.average_attendance') }}</div>
                    <h5>{{ $percentage }}%</h5>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold bg-dark text-white">
            {{ __('attendance.records') }}
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>{{ __('attendance.student') }}</th>
                        <th>{{ __('attendance.date') }}</th>
                        <th>{{ __('attendance.type') }}</th>
                        <th>{{ __('attendance.status') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($attendances as $attendance)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="fw-bold text-start">
                                {{ $attendance->enrollment->student->name ?? $attendance->enrollment->student->full_name ?? '—' }}
                            </td>
                            <td>{{ $attendance->date->format('Y-m-d') }}</td>
                            <td>{{ __('attendance.' . $attendance->type) }}</td>
                            <td>
                                <span class="badge bg-primary">
                                    {{ __('attendance.' . $attendance->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted py-4">
                                {{ __('attendance.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

@endsection
