@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="{{ route('dashboard.classes.index') }}" class="text-decoration-none small text-muted d-block mb-1">
                &larr; {{ __('timetable.classes_list') }}
            </a>
            <h3 class="mb-0">🗓️ {{ __('timetable.schedule_for') }} {{ $class->name_ru ?? $class->name }}</h3>
        </div>

        @can('manage timetable')
            <form action="{{ route('dashboard.classes.timetable.generate', $class) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-success">
                    {{ __('timetable.generate_smart_timetable') }}
                </button>
            </form>
        @endcan
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold">
            {{ __('timetable.weekly_schedule') }}
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered text-center align-middle mb-0 timetable-grid">
                    <thead class="table-light">
                        <tr>
                            <th class="timetable-lesson-col">{{ __('timetable.lesson') }}</th>
                            @foreach ($days as $day)
                                <th>{{ $day->name }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($periods as $period)
                            <tr>
                                <td class="fw-bold timetable-lesson-col">{{ $period->number ?? $period->name }}</td>

                                @foreach ($days as $day)
                                    @php $lesson = $lessons->get($day->id.'-'.$period->id); @endphp
                                    @php $cellId = 'cell-'.$day->id.'-'.$period->id; @endphp

                                    <td class="timetable-cell">
                                        @can('manage timetable')
                                            <button
                                                type="button"
                                                class="{{ $lesson ? 'lesson-card-btn' : 'add-lesson-btn' }}"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#editor-{{ $cellId }}"
                                                aria-expanded="false"
                                                aria-controls="editor-{{ $cellId }}"
                                            >
                                                @if ($lesson)
                                                    <span class="lesson-card">
                                                        <span class="lesson-card-subject">{{ $lesson->subject->name_ru }}</span>
                                                        <span class="lesson-card-teacher" title="{{ $lesson->teacher->full_name ?? '' }}">{{ $lesson->teacher->full_name ?? '' }}</span>
                                                    </span>
                                                @else
                                                    <span class="add-lesson-label">+ {{ __('timetable.add_lesson') }}</span>
                                                @endif
                                            </button>

                                            <div class="collapse timetable-editor" id="editor-{{ $cellId }}">
                                                <form action="{{ route('dashboard.classes.timetable.save', $class) }}" method="POST" class="timetable-cell-form text-start">
                                                    @csrf
                                                    <input type="hidden" name="day_id" value="{{ $day->id }}">
                                                    <input type="hidden" name="period_id" value="{{ $period->id }}">

                                                    <select name="subject_id" class="form-select form-select-sm mb-1 timetable-subject-select" required>
                                                        <option value="">{{ __('timetable.select_subject') }}</option>
                                                        @foreach ($subjects as $subject)
                                                            <option value="{{ $subject->id }}" @selected($lesson?->subject_id === $subject->id)>
                                                                {{ $subject->name_ru }}
                                                            </option>
                                                        @endforeach
                                                    </select>

                                                    <select name="teacher_id" class="form-select form-select-sm mb-1 timetable-teacher-select" required>
                                                        <option value="">{{ __('timetable.select_teacher') }}</option>
                                                        @if ($lesson)
                                                            <option value="{{ $lesson->teacher_id }}" selected>{{ $lesson->teacher->full_name ?? '' }}</option>
                                                        @endif
                                                    </select>

                                                    <div class="d-flex gap-1">
                                                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                                            {{ __('timetable.save') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-secondary btn-sm"
                                                            data-bs-toggle="collapse"
                                                            data-bs-target="#editor-{{ $cellId }}"
                                                        >
                                                            {{ __('timetable.cancel') }}
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        @else
                                            @if ($lesson)
                                                <span class="lesson-card lesson-card-readonly">
                                                    <span class="lesson-card-subject">{{ $lesson->subject->name_ru }}</span>
                                                    <span class="lesson-card-teacher" title="{{ $lesson->teacher->full_name ?? '' }}">{{ $lesson->teacher->full_name ?? '' }}</span>
                                                </span>
                                            @endif
                                        @endcan
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
    .timetable-grid {
        table-layout: fixed;
    }

    .timetable-grid .timetable-lesson-col {
        width: 64px;
    }

    .timetable-cell {
        width: 170px;
        min-width: 170px;
        max-width: 220px;
        vertical-align: top;
        padding: 6px;
    }

    .add-lesson-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 48px;
        border: 1px dashed #ced4da;
        border-radius: .5rem;
        background: transparent;
        color: #6c757d;
        font-size: .8rem;
    }

    .add-lesson-btn:hover {
        background: #f8f9fa;
        color: #495057;
    }

    .lesson-card-btn {
        display: block;
        width: 100%;
        border: 0;
        padding: 0;
        background: transparent;
        text-align: start;
    }

    .lesson-card {
        display: block;
        border-radius: .5rem;
        padding: .5rem .6rem;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
    }

    .lesson-card-subject {
        display: block;
        font-weight: 600;
        font-size: .85rem;
        color: #1e1b4b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .lesson-card-teacher {
        display: block;
        font-size: .72rem;
        color: #6366f1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 2px;
    }

    .lesson-card-readonly {
        cursor: default;
    }

    .timetable-editor {
        margin-top: 6px;
    }
</style>

@can('manage timetable')
    @push('scripts')
        <script>
            (function () {
                var teachersBySubject = @json($teachersBySubject);
                var placeholderLabel = @json(__('timetable.select_teacher'));

                document.querySelectorAll('.timetable-subject-select').forEach(function (subjectSelect) {
                    subjectSelect.addEventListener('change', function () {
                        var form = subjectSelect.closest('form');
                        var teacherSelect = form.querySelector('.timetable-teacher-select');
                        var teachers = teachersBySubject[subjectSelect.value] || [];

                        teacherSelect.innerHTML = '';

                        var placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = placeholderLabel;
                        teacherSelect.appendChild(placeholder);

                        teachers.forEach(function (teacher) {
                            var option = document.createElement('option');
                            option.value = teacher.id;
                            option.textContent = teacher.name;
                            teacherSelect.appendChild(option);
                        });
                    });
                });
            })();
        </script>
    @endpush
@endcan

@endsection
