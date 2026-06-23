@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <h3 class="fw-bold mb-4">
        📊 {{ __('attendance.class_report') }}
    </h3>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('dashboard.attendance.reports.class') }}" class="row g-2">

                <div class="col-md-3">
                    <select name="class_id" class="form-select" required>
                        <option value="">{{ __('attendance.select_class') }}</option>

                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" @selected(request('class_id') == $class->id)>
                                {{ $class->name_ru ?? $class->code }}
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

                <div class="col-md-2">
                    <select name="type" class="form-select">
                        <option value="">{{ __('attendance.all_types') }}</option>
                        <option value="daily" @selected(request('type') === 'daily')>{{ __('attendance.daily') }}</option>
                        <option value="period" @selected(request('type') === 'period')>{{ __('attendance.period') }}</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <button class="btn btn-primary w-100">
                        🔍 {{ __('attendance.filter') }}
                    </button>
                </div>

            </form>
        </div>
    </div>

    @if($selectedClass)
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body">
                        <div class="text-muted">{{ __('attendance.class') }}</div>
                        <h5>{{ $selectedClass->name_ru ?? $selectedClass->code }}</h5>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body">
                        <div class="text-muted">{{ __('attendance.records') }}</div>
                        <h5>{{ $attendances->count() }}</h5>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body">
                        <div class="text-muted">{{ __('attendance.students') }}</div>
                        <h5>{{ $summary->count() }}</h5>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body">
                        <div class="text-muted">{{ __('attendance.average_attendance') }}</div>
                        <h5>{{ $summary->count() ? round($summary->avg('percentage'), 2) : 0 }}%</h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header fw-bold bg-dark text-white">
                {{ __('attendance.summary') }}
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>{{ __('attendance.student') }}</th>
                            <th>✅ {{ __('attendance.present') }}</th>
                            <th>❌ {{ __('attendance.absent') }}</th>
                            <th>⏰ {{ __('attendance.late') }}</th>
                            <th>📝 {{ __('attendance.excused') }}</th>
                            <th>{{ __('attendance.total') }}</th>
                            <th>%</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($summary as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td class="fw-bold text-start">
                                    {{ $row['student']->name ?? $row['student']->full_name ?? '—' }}
                                </td>
                                <td>{{ $row['present'] }}</td>
                                <td>{{ $row['absent'] }}</td>
                                <td>{{ $row['late'] }}</td>
                                <td>{{ $row['excused'] }}</td>
                                <td>{{ $row['total'] }}</td>
                                <td>
                                    <span class="badge bg-primary">
                                        {{ $row['percentage'] }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-muted py-4">
                                    {{ __('attendance.no_data') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>

@endsection