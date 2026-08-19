@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <a href="{{ route('dashboard.classes.index') }}" class="text-decoration-none small text-muted d-block mb-1">
                &larr; {{ __('timetable.classes_list') }}
            </a>
            <h3 class="mb-0">🗓️ {{ __('timetable.schedule_for') }} {{ $class->name_ru ?? $class->name }}</h3>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('dashboard.classes.timetable.pdf', $class) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                {{ __('timetable.pdf_preview') }}
            </a>
            <a href="{{ route('dashboard.classes.timetable.pdf.download', $class) }}" class="btn btn-outline-secondary">
                {{ __('timetable.pdf_download') }}
            </a>
            <a href="{{ route('dashboard.classes.timetable.print', $class) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                {{ __('timetable.print') }}
            </a>

            @can('manage timetable')
                <form action="{{ route('dashboard.classes.timetable.generate', $class) }}" method="POST"
                      onsubmit="return confirm('{{ __('timetable.confirm_generate') }}')">
                    @csrf
                    <button type="submit" class="btn btn-success">
                        {{ __('timetable.generate_smart_timetable') }}
                    </button>
                </form>
            @endcan
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold">
            {{ __('timetable.weekly_schedule') }}
        </div>

        <div class="card-body p-0">
            <div class="timetable-scroll">
                <table class="table table-bordered text-center align-middle mb-0 timetable-grid">
                    <thead class="table-light">
                        <tr>
                            <th class="timetable-lesson-col">{{ __('timetable.lesson') }}</th>
                            @foreach ($days as $day)
                                <th class="timetable-day-col">{{ $day->name }}</th>
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
                                                data-timetable-toggle="editor-{{ $cellId }}"
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

                                            <div class="timetable-editor" id="editor-{{ $cellId }}" data-timetable-editor>
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
                                                            data-timetable-toggle="editor-{{ $cellId }}"
                                                        >
                                                            {{ __('timetable.cancel') }}
                                                        </button>
                                                    </div>
                                                </form>

                                                @if ($lesson)
                                                    <form action="{{ route('dashboard.classes.timetable.destroy', $class) }}" method="POST" class="mt-1"
                                                          onsubmit="return confirm('{{ __('timetable.confirm_delete') }}')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <input type="hidden" name="day_id" value="{{ $day->id }}">
                                                        <input type="hidden" name="period_id" value="{{ $period->id }}">

                                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                                            {{ __('timetable.delete_lesson') }}
                                                        </button>
                                                    </form>
                                                @endif
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
    /*
     * Bounded-height, both-axis scroll container: horizontal scroll stays
     * confined to the timetable (not the whole Dashboard page), and the
     * sticky thead below relies on THIS container's own scroll, not the
     * document's — so it never competes with the Dashboard topbar's own
     * sticky/z-index stack.
     */
    .timetable-scroll {
        overflow: auto;
        max-height: 75vh;
    }

    .timetable-grid {
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }

    .timetable-grid .timetable-lesson-col {
        width: 64px;
        min-width: 64px;
    }

    .timetable-grid .timetable-day-col {
        min-width: 170px;
    }

    .timetable-grid thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background-color: #f8f9fa;
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
        cursor: pointer;
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
        cursor: pointer;
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
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        font-size: .72rem;
        line-height: 1.2;
        color: #6366f1;
        margin-top: 2px;
    }

    .lesson-card-readonly {
        cursor: default;
    }

    .timetable-editor {
        display: none;
        margin-top: 6px;
    }

    .timetable-editor.is-open {
        display: block;
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

                // Self-contained editor toggle: intentionally does not rely on
                // Bootstrap's JS bundle (loaded from an external CDN with no
                // fallback) so a CDN hiccup can never make "+ Добавить урок"
                // or an existing lesson card unclickable again.
                document.querySelectorAll('[data-timetable-toggle]').forEach(function (trigger) {
                    trigger.addEventListener('click', function () {
                        var targetId = trigger.getAttribute('data-timetable-toggle');
                        var target = document.getElementById(targetId);

                        if (!target) {
                            return;
                        }

                        var willOpen = !target.classList.contains('is-open');

                        document.querySelectorAll('[data-timetable-editor].is-open').forEach(function (openEditor) {
                            if (openEditor !== target) {
                                openEditor.classList.remove('is-open');

                                var openTrigger = document.querySelector('[data-timetable-toggle="' + openEditor.id + '"][aria-expanded]');
                                if (openTrigger) {
                                    openTrigger.setAttribute('aria-expanded', 'false');
                                }
                            }
                        });

                        target.classList.toggle('is-open', willOpen);

                        document.querySelectorAll('[data-timetable-toggle="' + targetId + '"][aria-expanded]').forEach(function (relatedTrigger) {
                            relatedTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                        });
                    });
                });
            })();
        </script>
    @endpush
@endcan

@endsection
