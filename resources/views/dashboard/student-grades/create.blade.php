@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">➕ {{ __('grades.add_grade') }}</h3>

        <a href="{{ route('dashboard.grades.index') }}" class="btn btn-secondary">
            {{ __('grades.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body">

            <form action="{{ route('dashboard.grades.store') }}" method="POST">
                @csrf

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="student_id" class="form-label">{{ __('grades.student') }}</label>
                        <select name="student_id" id="student_id" class="form-select" required>
                            <option value="">{{ __('grades.select_student') }}</option>
                            @foreach($students as $student)
                                <option value="{{ $student->id }}" {{ old('student_id') == $student->id ? 'selected' : '' }}>
                                    {{ $student->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="subject_id" class="form-label">{{ __('grades.subject') }}</label>
                        <select name="subject_id" id="subject_id" class="form-select" required>
                            <option value="">{{ __('grades.select_subject') }}</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>
                                    {{ $subject->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="exam_id" class="form-label">{{ __('grades.exam') }}</label>
                        <select name="exam_id" id="exam_id" class="form-select" required>
                            <option value="">{{ __('grades.select_exam') }}</option>
                            @foreach($exams as $exam)
                                <option value="{{ $exam->id }}" {{ old('exam_id') == $exam->id ? 'selected' : '' }}>
                                    {{ $exam->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="quarter_id" class="form-label">{{ __('grades.quarter') }}</label>
                        <select name="quarter_id" id="quarter_id" class="form-select">
                            <option value="">{{ __('grades.select_quarter') }}</option>
                            @forelse($quarters as $quarter)
                                <option value="{{ $quarter->id }}" {{ old('quarter_id') == $quarter->id ? 'selected' : '' }}>
                                    {{ $quarter->name }}
                                </option>
                            @empty
                            @endforelse
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="score" class="form-label">{{ __('grades.score') }}</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            name="score"
                            id="score"
                            class="form-control"
                            value="{{ old('score') }}"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label for="note" class="form-label">{{ __('grades.note') }}</label>
                        <input
                            type="text"
                            name="note"
                            id="note"
                            class="form-control"
                            value="{{ old('note') }}"
                        >
                    </div>

                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        {{ __('grades.save') }}
                    </button>

                    <a href="{{ route('dashboard.grades.index') }}" class="btn btn-light">
                        {{ __('grades.cancel') }}
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

@endsection