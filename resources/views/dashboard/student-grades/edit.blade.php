@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">✏️ {{ __('grades.edit') }}</h3>

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

            <form action="{{ route('dashboard.grades.update', $grade->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="row g-3">

                    {{-- Student --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.student') }}</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">{{ __('grades.select_student') }}</option>
                            @foreach($students as $student)
                                <option value="{{ $student->id }}"
                                    {{ old('student_id', $grade->student_id) == $student->id ? 'selected' : '' }}>
                                    {{ $student->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Subject --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.subject') }}</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">{{ __('grades.select_subject') }}</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}"
                                    {{ old('subject_id', $grade->subject_id) == $subject->id ? 'selected' : '' }}>
                                    {{ $subject->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Exam --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.exam') }}</label>
                        <select name="exam_id" class="form-select" required>
                            <option value="">{{ __('grades.select_exam') }}</option>
                            @foreach($exams as $exam)
                                <option value="{{ $exam->id }}"
                                    {{ old('exam_id', $grade->exam_id) == $exam->id ? 'selected' : '' }}>
                                    {{ $exam->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Quarter --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.quarter') }}</label>
                        <select name="quarter_id" class="form-select">
                            <option value="">{{ __('grades.select_quarter') }}</option>
                            @forelse($quarters as $quarter)
                                <option value="{{ $quarter->id }}"
                                    {{ old('quarter_id', $grade->quarter_id) == $quarter->id ? 'selected' : '' }}>
                                    {{ $quarter->name }}
                                </option>
                            @empty
                            @endforelse
                        </select>
                    </div>

                    {{-- Score --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.score') }}</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            name="score"
                            class="form-control"
                            value="{{ old('score', $grade->score) }}"
                            required
                        >
                    </div>

                    {{-- Note --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('grades.note') }}</label>
                        <input
                            type="text"
                            name="note"
                            class="form-control"
                            value="{{ old('note', $grade->note) }}"
                        >
                    </div>

                </div>

                {{-- Buttons --}}
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