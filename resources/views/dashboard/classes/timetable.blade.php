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
                <table class="table table-bordered text-center align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('timetable.lesson') }}</th>
                            @foreach ($days as $day)
                                <th>{{ $day->name }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($periods as $period)
                            <tr>
                                <td class="fw-bold">{{ $period->number ?? $period->name }}</td>

                                @foreach ($days as $day)
                                    @php $lesson = $lessons->get($day->id.'-'.$period->id); @endphp

                                    <td style="min-width: 190px;">
                                        @if ($lesson)
                                            <div class="rounded p-2 mb-2 text-white" style="background-color: {{ $lesson->subject->color ?? '#6366f1' }}">
                                                <div class="fw-bold">{{ $lesson->subject->name_ru }}</div>
                                                <div class="small">{{ $lesson->teacher->full_name ?? '' }}</div>
                                            </div>
                                        @endif

                                        @can('manage timetable')
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

                                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                                    {{ __('timetable.save') }}
                                                </button>
                                            </form>
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
