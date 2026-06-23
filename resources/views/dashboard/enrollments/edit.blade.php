@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">✏️ {{ __('enrollments.edit') }}</h3>

        <a href="{{ route('dashboard.enrollments.index') }}" class="btn btn-secondary">
            {{ __('enrollments.back') }}
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

            <form method="POST" action="{{ route('dashboard.enrollments.update', $enrollment->id) }}">
                @csrf
                @method('PUT')

                {{-- Student --}}
                <div class="mb-3">
                    <label class="form-label">{{ __('enrollments.student') }}</label>
                    <input type="text" class="form-control" value="{{ $enrollment->student->full_name }}" disabled>
                </div>

                <div class="row g-3">

                    {{-- Academic Year --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('enrollments.academic_year') }}</label>

                        <input type="text"
                               name="academic_year"
                               class="form-control"
                               value="{{ old('academic_year', $enrollment->academic_year) }}"
                               placeholder="2025/2026">
                    </div>

                    {{-- Date --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('enrollments.enrollment_date') }}</label>

                        <input type="date"
                               name="enrollment_date"
                               class="form-control"
                               value="{{ old('enrollment_date', optional($enrollment->enrolled_at)->format('Y-m-d')) }}">
                    </div>

                    {{-- Stage --}}
                    <div class="col-md-4">
                        <label class="form-label">{{ __('enrollments.stage') }}</label>

                        <select name="stage_id" id="stageSelect" class="form-select" required>
                            <option value="">{{ __('enrollments.select_stage') }}</option>
                            @foreach($stages as $stage)
                                <option value="{{ $stage->id }}"
                                        @selected(old('stage_id', $enrollment->stage_id) == $stage->id)>
                                    {{ $stage->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Grade --}}
                    <div class="col-md-4">
                        <label class="form-label">{{ __('enrollments.grade') }}</label>

                        <select name="grade_id" id="gradeSelect" class="form-select" required>
                            <option value="">{{ __('enrollments.select_grade') }}</option>

                            @foreach($grades as $grade)
                                <option value="{{ $grade->id }}"
                                        data-stage="{{ $grade->stage_id }}"
                                        @selected(old('grade_id', $enrollment->grade_id) == $grade->id)>
                                    {{ $grade->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Class --}}
                    <div class="col-md-4">
                        <label class="form-label">{{ __('enrollments.class') }}</label>

                        <select name="class_id" id="classSelect" class="form-select" required>
                            <option value="">{{ __('enrollments.select_class') }}</option>

                            @foreach($classes as $class)
                                <option value="{{ $class->id }}"
                                        data-grade="{{ $class->grade_id }}"
                                        @selected(old('class_id', $enrollment->class_id) == $class->id)>
                                    {{ $class->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Status --}}
                    <div class="col-md-6">
                        <label class="form-label">{{ __('enrollments.status') }}</label>

                        <select name="status" class="form-select" required>
                            <option value="">{{ __('enrollments.select_status') }}</option>
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}"
                                        @selected(old('status', $enrollment->status) == $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Notes --}}
                    <div class="col-md-12">
                        <label class="form-label">{{ __('enrollments.notes') }}</label>

                        <textarea name="notes" class="form-control" rows="3">
{{ old('notes', $enrollment->notes) }}
                        </textarea>
                    </div>

                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-success">
                        💾 {{ __('enrollments.save') }}
                    </button>

                    <a href="{{ route('dashboard.enrollments.index') }}" class="btn btn-secondary">
                        {{ __('enrollments.cancel') }}
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const stageSelect = document.getElementById('stageSelect');
    const gradeSelect = document.getElementById('gradeSelect');
    const classSelect = document.getElementById('classSelect');

    function filterGrades(reset = false) {
        const stageId = stageSelect.value;

        Array.from(gradeSelect.options).forEach(option => {
            if (!option.value) return;
            option.hidden = stageId && option.dataset.stage !== stageId;
        });

        if (reset) {
            gradeSelect.value = '';
            classSelect.value = '';
        }

        if (gradeSelect.selectedOptions[0] && gradeSelect.selectedOptions[0].hidden) {
            gradeSelect.value = '';
        }

        filterClasses(reset);
    }

    function filterClasses(reset = false) {
        const gradeId = gradeSelect.value;

        Array.from(classSelect.options).forEach(option => {
            if (!option.value) return;
            option.hidden = gradeId && option.dataset.grade !== gradeId;
        });

        if (reset) {
            classSelect.value = '';
        }

        if (classSelect.selectedOptions[0] && classSelect.selectedOptions[0].hidden) {
            classSelect.value = '';
        }
    }

    stageSelect.addEventListener('change', function () {
        filterGrades(true);
    });

    gradeSelect.addEventListener('change', function () {
        filterClasses(true);
    });

    // 🔥 مهم: يحافظ على الاختيارات الحالية
    filterGrades(false);
});
</script>
@endpush