@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">➕ {{ __('timetable.add_lesson') }}</h3>
            <small class="text-muted">{{ __('timetable.create_hint') }}</small>
        </div>

        <a href="{{ route('dashboard.timetable.index') }}" class="btn btn-outline-secondary">
            ← {{ __('timetable.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <strong>{{ __('timetable.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.timetable.store') }}">
        @csrf

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                {{ __('timetable.lesson_info') }}
            </div>

            <div class="card-body p-4">
                <div class="row g-4">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('timetable.class') }}</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_class') }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}"
                                    @selected(old('class_id', request('class_id')) == $class->id)>
                                    {{ $class->grade->stage->name ?? '' }}
                                    @if($class->grade?->stage) / @endif
                                    {{ $class->grade->name ?? '' }}
                                    @if($class->grade) / @endif
                                    {{ $class->name_ru ?? $class->code }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('timetable.day') }}</label>
                        <select name="day_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_day') }}</option>
                            @foreach($days as $day)
                                <option value="{{ $day->id }}"
                                    @selected(old('day_id', request('day_id')) == $day->id)>
                                    {{ $day->name_ru ?? $day->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('timetable.period') }}</label>
                        <select name="period_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_period') }}</option>
                            @foreach($periods as $period)
                                <option value="{{ $period->id }}"
                                    @selected(old('period_id', request('period_id')) == $period->id)>
                                    {{ __('timetable.lesson') }} {{ $period->number }}
                                    @if(!empty($period->start_time) || !empty($period->end_time))
                                        — {{ $period->start_time ?? '' }} - {{ $period->end_time ?? '' }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('timetable.subject') }}</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_subject') }}</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" @selected(old('subject_id') == $subject->id)>
                                    {{ $subject->name_ru ?? $subject->name ?? ('#' . $subject->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('timetable.teacher') }}</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_teacher') }}</option>
                            @foreach($teachers as $teacher)
                                <option value="{{ $teacher->id }}" @selected(old('teacher_id') == $teacher->id)>
                                    {{ $teacher->short_name }} — {{ $teacher->specialization ?? __('timetable.no_specialization') }}
                                </option>
                            @endforeach
                        </select>

                        <div class="text-muted small mt-1">
                            {{ __('timetable.teacher_conflict_hint') }}
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('timetable.room') }}</label>
                        <input type="text"
                               name="room"
                               value="{{ old('room') }}"
                               class="form-control"
                               placeholder="101">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-semibold">{{ __('timetable.notes') }}</label>
                        <input type="text"
                               name="notes"
                               value="{{ old('notes') }}"
                               class="form-control"
                               placeholder="{{ __('timetable.notes_placeholder') }}">
                    </div>

                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-success px-4">
                💾 {{ __('timetable.save') }}
            </button>

            <a href="{{ route('dashboard.timetable.index') }}" class="btn btn-outline-secondary px-4">
                {{ __('timetable.cancel') }}
            </a>
        </div>

    </form>

</div>

@endsection