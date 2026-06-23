@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">🖨 {{ __('student_grades.print_report') }}</h3>

        <a href="{{ route('dashboard.student-grades.index') }}" class="btn btn-secondary">
            {{ __('student_grades.back') }}
        </a>
    </div>

    <form method="POST" id="reportForm">
        @csrf

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                {{ __('student_grades.report_filters') }}
            </div>

            <div class="card-body row g-3">

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.class') }}</label>
                    <select name="class_id" class="form-select" required>
                        <option value="">{{ __('student_grades.select_class') }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}">
                                {{ $class->grade->stage->name ?? '' }}
                                @if($class->grade?->stage) / @endif
                                {{ $class->grade->name ?? '' }}
                                @if($class->grade) / @endif
                                {{ $class->name_ru }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.subject') }}</label>
                    <select name="subject_id" class="form-select">
                        <option value="">{{ __('student_grades.optional') }}</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}">
                                {{ $subject->name_ru ?? $subject->name ?? ('#' . $subject->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.exam') }}</label>
                    <select name="exam_id" class="form-select">
                        <option value="">{{ __('student_grades.optional') }}</option>
                        @foreach($exams as $exam)
                            <option value="{{ $exam->id }}">
                                {{ $exam->name ?? $exam->title ?? ('#' . $exam->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.quarter') }}</label>
                    <select name="quarter_id" class="form-select">
                        <option value="">{{ __('student_grades.optional') }}</option>
                        @foreach($quarters as $quarter)
                            <option value="{{ $quarter->id }}">
                                {{ $quarter->name ?? $quarter->title ?? ('#' . $quarter->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                {{ __('student_grades.choose_columns') }}
            </div>

            <div class="card-body row g-3">
                @php
                    $availableColumns = [
                        'student_name' => __('student_grades.columns.student_name'),
                        'short_name' => __('student_grades.columns.short_name'),
                        'class' => __('student_grades.columns.class'),
                        'phone' => __('student_grades.columns.phone'),
                        'email' => __('student_grades.columns.email'),
                        'nationality' => __('student_grades.columns.nationality'),
                        'birth_date' => __('student_grades.columns.birth_date'),
                        'gender' => __('student_grades.columns.gender'),
                        'subject' => __('student_grades.columns.subject'),
                        'exam' => __('student_grades.columns.exam'),
                        'quarter' => __('student_grades.columns.quarter'),
                        'score' => __('student_grades.columns.score'),
                        'note' => __('student_grades.columns.note'),
                    ];
                @endphp

                @foreach($availableColumns as $key => $label)
                    <div class="col-md-3">
                        <label class="form-check">
                            <input type="checkbox"
                                   name="columns[]"
                                   value="{{ $key }}"
                                   class="form-check-input"
                                   @checked(in_array($key, ['student_name', 'class', 'phone']))>
                            <span class="form-check-label">{{ $label }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit"
                    class="btn btn-primary"
                    formaction="{{ route('dashboard.student-grades.report.generate') }}">
                🖨 {{ __('student_grades.generate_report') }}
            </button>

            <button type="submit"
                    class="btn btn-danger"
                    formaction="{{ route('dashboard.student-grades.report.pdf') }}">
                📄 PDF
            </button>
        </div>

    </form>

</div>

@endsection