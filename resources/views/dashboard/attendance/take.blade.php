@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                📋 {{ __('attendance.take_attendance') }} - {{ $class->name_ru ?? $class->code }}
            </h3>

            <small class="text-muted">
                {{ __('attendance.date') }}: {{ $date }}
            </small>
        </div>

        <a href="{{ route('dashboard.attendance.index') }}" class="btn btn-secondary">
            ← {{ __('attendance.back') ?? 'Назад' }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.attendance.store') }}">
        @csrf

        <input type="hidden" name="class_id" value="{{ $class->id }}">
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="type" value="{{ $type }}">

        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold">
                {{ $type === 'daily' ? __('attendance.daily') : __('attendance.period') }}
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">

                    <table class="table table-bordered align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>{{ __('attendance.student') }}</th>

                                @if($type === 'daily')
                                    <th style="width: 260px;">{{ __('attendance.status') }}</th>
                                @else
                                    @foreach($periods as $period)
                                        <th>
                                            {{ __('timetable.lesson') }} {{ $period->number }}
                                        </th>
                                    @endforeach
                                @endif
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($students as $enrollment)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>

                                    <td class="fw-bold text-start">
                                        {{ $enrollment->student->name ?? $enrollment->student->full_name ?? '—' }}
                                    </td>

                                    @if($type === 'daily')
                                        <td>
                                            <select name="attendance[{{ $enrollment->id }}][status]"
                                                    class="form-select">
                                                <option value="present">{{ __('attendance.present') }}</option>
                                                <option value="absent">{{ __('attendance.absent') }}</option>
                                                <option value="late">{{ __('attendance.late') }}</option>
                                                <option value="excused">{{ __('attendance.excused') }}</option>
                                            </select>
                                        </td>
                                    @else
                                        @foreach($periods as $period)
                                            <td>
                                                <select name="attendance[{{ $enrollment->id }}][{{ $period->id }}]"
                                                        class="form-select">
                                                    <option value="present">✅ {{ __('attendance.present') }}</option>
                                                    <option value="absent">❌ {{ __('attendance.absent') }}</option>
                                                    <option value="late">⏰ {{ __('attendance.late') }}</option>
                                                    <option value="excused">📝 {{ __('attendance.excused') }}</option>
                                                </select>
                                            </td>
                                        @endforeach
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $type === 'daily' ? 3 : 2 + $periods->count() }}"
                                        class="text-center py-5 text-muted">
                                        {{ __('attendance.no_students') ?? 'Нет учеников в этом классе' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                </div>
            </div>
        </div>

        <button class="btn btn-success mt-3 px-4">
            💾 {{ __('attendance.save') }}
        </button>

    </form>

</div>

@endsection