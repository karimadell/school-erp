@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                👨‍🏫 {{ $teacher->full_name }}
            </h3>

            <small class="text-muted">
                {{ $teacher->specialization ?? '—' }}
            </small>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.teachers.teacherPdf', $teacher->id) }}" class="btn btn-danger">
                📄 PDF
            </a>

            <a href="{{ route('dashboard.teachers.edit', $teacher->id) }}" class="btn btn-warning">
                ✏ {{ __('teachers.edit') }}
            </a>

            <a href="{{ route('dashboard.teachers.documents', $teacher->id) }}" class="btn btn-info">
                📎 {{ __('teachers.documents') }}
            </a>

            <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-secondary">
                ← {{ __('teachers.back') }}
            </a>
        </div>
    </div>

    {{-- Info --}}
    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">📞 {{ __('teachers.phone') }}</div>
                    <div class="fw-bold">{{ $teacher->phone ?? '—' }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">📧 Email</div>
                    <div class="fw-bold">{{ $teacher->email ?? '—' }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">📅 {{ __('teachers.hire_date') }}</div>
                    <div class="fw-bold">
                        {{ $teacher->hire_date?->format('Y-m-d') ?? '—' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body">
                    <div class="text-muted">⚡ {{ __('teachers.status') }}</div>

                    @if($teacher->is_active)
                        <span class="badge bg-success">
                            {{ __('teachers.active') }}
                        </span>
                    @else
                        <span class="badge bg-secondary">
                            {{ __('teachers.inactive') }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

    </div>

    {{-- Subjects --}}
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header fw-bold">
            📚 {{ __('teachers.subjects') }}
        </div>

        <div class="card-body">
            @forelse($teacher->subjects as $subject)
                <span class="badge bg-primary-subtle text-dark mb-1">
                    {{ $subject->name_ru }}
                </span>
            @empty
                <span class="text-muted">—</span>
            @endforelse
        </div>
    </div>

    {{-- Documents --}}
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header fw-bold d-flex justify-content-between align-items-center">
            <span>📎 {{ __('teachers.documents') }}</span>

            <a href="{{ route('dashboard.teachers.documents', $teacher->id) }}"
               class="btn btn-sm btn-outline-primary">
                + {{ __('teachers.documents') }}
            </a>
        </div>

        <div class="card-body">
            @forelse($teacher->documents as $document)
                <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">
                    <div>
                        <strong>{{ $document->title }}</strong>
                        <div class="small text-muted">
                            {{ $document->document_date?->format('Y-m-d') ?? '—' }}
                        </div>
                    </div>

                    <a href="{{ asset('storage/' . $document->file_path) }}"
                       target="_blank"
                       class="btn btn-sm btn-outline-dark">
                        👁
                    </a>
                </div>
            @empty
                <span class="text-muted">—</span>
            @endforelse
        </div>
    </div>

    {{-- Schedule --}}
    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold bg-dark text-white d-flex justify-content-between">
            <span>📅 Weekly Schedule</span>
            <small class="text-white-50">{{ $teacher->short_name }}</small>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-bordered text-center mb-0 teacher-schedule-table">

                    <thead class="table-light">
                        <tr>
                            <th style="width:160px">{{ __('timetable.period') }}</th>
                            @foreach($days as $day)
                                <th>{{ $day->name_ru ?? $day->name }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($periods as $period)
                            <tr>

                                <td class="fw-bold bg-light">
                                    {{ __('timetable.lesson') }} {{ $period->number }}

                                    <div class="text-muted small">
                                        {{ $period->start_time }} - {{ $period->end_time }}
                                    </div>
                                </td>

                                @foreach($days as $day)

                                    @php
                                        $lesson = $teacher->timetables
                                            ->where('day_id', $day->id)
                                            ->where('period_id', $period->id)
                                            ->first();
                                    @endphp

                                    <td class="schedule-cell">

                                        @if($lesson)
                                            <div class="lesson-box">

                                                <div class="fw-bold text-primary">
                                                    {{ $lesson->subject->name_ru ?? '—' }}
                                                </div>

                                                <div class="small mt-1">
                                                    🏫 {{ $lesson->class->name_ru ?? $lesson->class->code ?? '—' }}
                                                </div>

                                                @if($lesson->room)
                                                    <div class="small text-muted mt-1">
                                                        📍 {{ $lesson->room }}
                                                    </div>
                                                @endif

                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif

                                    </td>

                                @endforeach

                            </tr>
                        @endforeach
                    </tbody>

                </table>

            </div>
        </div>
    </div>

</div>

<style>
.teacher-schedule-table th,
.teacher-schedule-table td {
    vertical-align: middle;
}

.schedule-cell {
    min-width: 170px;
    height: 120px;
    background: #fafafa;
}

.lesson-box {
    background: #fff;
    border-radius: 14px;
    padding: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    transition: 0.2s;
}

.lesson-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.10);
}
</style>

@endsection