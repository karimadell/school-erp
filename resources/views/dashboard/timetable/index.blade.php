@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4 school-timetable-page">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <h3 class="mb-0">🏫 {{ __('timetable.school_wide_title') }}</h3>
    </div>

    <form method="GET" action="{{ route('dashboard.school-timetable.index') }}" class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-2">
            <div>
                <label class="form-label mb-1 small text-muted">{{ __('timetable.filter_classes') }}</label>
                <select name="classes[]" multiple class="form-select" style="min-width: 260px;">
                    @foreach ($allClasses as $class)
                        <option value="{{ $class->id }}" @selected($selectedIds->contains($class->id))>
                            {{ $class->name_ru ?? $class->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('timetable.apply_filter') }}</button>
            <a href="{{ route('dashboard.school-timetable.index') }}" class="btn btn-outline-secondary">{{ __('timetable.all_classes') }}</a>
        </div>
    </form>

    @foreach ($selectedClasses as $class)
        @php $lessons = $lessonsByClass->get($class->id, collect()); @endphp

        <div class="card shadow-sm border-0 mb-4" data-school-class-id="{{ $class->id }}">
            <div class="card-header fw-bold d-flex justify-content-between align-items-center">
                <span>{{ $class->name_ru ?? $class->name }}</span>
                <a href="{{ route('dashboard.classes.timetable', $class) }}" class="btn btn-sm btn-outline-secondary">
                    {{ __('timetable.open_class_timetable') }}
                </a>
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
                                    <td class="fw-bold timetable-lesson-col">{{ $period->number }}</td>
                                    @foreach ($days as $day)
                                        @php $cellLessons = $lessons->get($day->id.'-'.$period->id, collect()); @endphp
                                        <td class="timetable-cell {{ $cellLessons->count() > 1 ? 'timetable-cell-conflict' : '' }}">
                                            @if ($cellLessons->isNotEmpty())
                                                @foreach ($cellLessons as $lesson)
                                                    <span data-timetable-lesson-id="{{ $lesson->id }}" class="lesson-card {{ $teacherConflictLessons->has($lesson->id) || $classConflictLessons->has($lesson->id) || $duplicateLessons->has($lesson->id) ? 'lesson-card-conflict' : '' }}">
                                                        <span class="lesson-card-subject">{{ $lesson->subject->name_ru }}</span>
                                                        <span class="lesson-card-teacher">{{ $lesson->teacher->full_name ?? '' }}</span>
                                                        <span class="lesson-card-conflicts">
                                                            @if ($teacherConflictLessons->has($lesson->id))
                                                                <span class="badge bg-danger">{{ __('timetable.teacher_conflict_badge') }}</span>
                                                            @endif
                                                            @if ($classConflictLessons->has($lesson->id))
                                                                <span class="badge bg-warning text-dark">{{ __('timetable.class_conflict_badge') }}</span>
                                                            @endif
                                                            @if ($duplicateLessons->has($lesson->id))
                                                                <span class="badge bg-dark">{{ __('timetable.duplicate_cell_badge') }}</span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                @endforeach
                                            @else
                                                <span class="timetable-empty-cell" aria-label="{{ __('timetable.empty_slot') }}">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($lessons->isEmpty())
                    <p class="text-muted p-3 mb-0">{{ __('timetable.no_lessons_yet') }}</p>
                @endif
            </div>
        </div>
    @endforeach

    @if ($selectedClasses->isEmpty())
        <div class="alert alert-light border text-muted">{{ __('timetable.no_active_classes') }}</div>
    @endif

</div>

@include('dashboard.timetable.partials.styles')

@endsection
