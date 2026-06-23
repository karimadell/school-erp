@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">⚡ {{ __('student_grades.bulk_entry') }}</h3>

        <a href="{{ route('dashboard.student-grades.index') }}" class="btn btn-secondary">
            {{ __('student_grades.back') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div id="bulk-alert" class="alert d-none"></div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="row g-3">

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.class') }}</label>
                    <select id="class_id" class="form-select">
                        <option value="">{{ __('student_grades.select_class') }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.subject') }}</label>
                    <select id="subject_id" class="form-select">
                        <option value="">{{ __('student_grades.select_subject') }}</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}">
                                {{ $subject->name_ru ?? $subject->name ?? ('#' . $subject->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.exam') }}</label>
                    <select id="exam_id" class="form-select">
                        <option value="">{{ __('student_grades.select_exam') }}</option>
                        @foreach($exams as $exam)
                            <option value="{{ $exam->id }}">
                                {{ $exam->name ?? $exam->title ?? ('#' . $exam->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('student_grades.quarter') }}</label>
                    <select id="quarter_id" class="form-select">
                        <option value="">{{ __('student_grades.select_quarter') }}</option>
                        @foreach($quarters as $quarter)
                            <option value="{{ $quarter->id }}">
                                {{ $quarter->name ?? $quarter->title ?? ('#' . $quarter->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

            </div>

            <div class="mt-3">
                <button type="button" id="loadStudentsBtn" class="btn btn-primary">
                    {{ __('student_grades.load_students') }}
                </button>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboard.student-grades.bulk.store') }}">
        @csrf

        <input type="hidden" name="class_id" id="form_class_id">
        <input type="hidden" name="subject_id" id="form_subject_id">
        <input type="hidden" name="exam_id" id="form_exam_id">
        <input type="hidden" name="quarter_id" id="form_quarter_id">

        <div class="card shadow-sm border-0">
            <div class="card-header fw-bold">
                {{ __('student_grades.students_grades') }}
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 80px;">#</th>
                                <th>{{ __('student_grades.student') }}</th>
                                <th style="width: 180px;">{{ __('student_grades.score') }}</th>
                                <th>{{ __('student_grades.note') }}</th>
                            </tr>
                        </thead>

                        <tbody id="studentsTableBody">
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    {{ __('student_grades.select_class_first') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-success" id="saveBulkBtn" disabled>
                    💾 {{ __('student_grades.save') }}
                </button>
            </div>
        </div>
    </form>

</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const examSelect = document.getElementById('exam_id');
    const quarterSelect = document.getElementById('quarter_id');

    const formClassId = document.getElementById('form_class_id');
    const formSubjectId = document.getElementById('form_subject_id');
    const formExamId = document.getElementById('form_exam_id');
    const formQuarterId = document.getElementById('form_quarter_id');

    const loadBtn = document.getElementById('loadStudentsBtn');
    const saveBtn = document.getElementById('saveBulkBtn');
    const tableBody = document.getElementById('studentsTableBody');
    const alertBox = document.getElementById('bulk-alert');

    function showAlert(type, message) {
        alertBox.className = 'alert alert-' + type;
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
    }

    function clearAlert() {
        alertBox.className = 'alert d-none';
        alertBox.textContent = '';
    }

    function resetTable(message) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted py-4">
                    ${message}
                </td>
            </tr>
        `;
        saveBtn.disabled = true;
    }

    loadBtn.addEventListener('click', async function () {
        clearAlert();

        const classId = classSelect.value;
        const subjectId = subjectSelect.value;
        const examId = examSelect.value;
        const quarterId = quarterSelect.value;

        if (!classId || !subjectId || !examId) {
            showAlert('warning', @json(__('student_grades.required_filters')));
            resetTable(@json(__('student_grades.select_class_first')));
            return;
        }

        formClassId.value = classId;
        formSubjectId.value = subjectId;
        formExamId.value = examId;
        formQuarterId.value = quarterId;

        loadBtn.disabled = true;
        saveBtn.disabled = true;
        loadBtn.textContent = @json(__('student_grades.loading'));

        resetTable(@json(__('student_grades.loading')));

        try {
            const params = new URLSearchParams({
                class_id: classId,
                subject_id: subjectId,
                exam_id: examId,
                quarter_id: quarterId
            });

            const response = await fetch(@json(route('dashboard.student-grades.bulk.students')) + '?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || @json(__('student_grades.load_failed')));
            }

            tableBody.innerHTML = '';

            if (!data.students || data.students.length === 0) {
                resetTable(@json(__('student_grades.no_students_in_class')));
                return;
            }

            data.students.forEach((student, index) => {
                const scoreValue = student.score ?? '';
                const noteValue = student.note ?? '';

                tableBody.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${student.full_name}</td>
                        <td>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                name="grades[${student.id}][score]"
                                class="form-control"
                                value="${scoreValue}"
                            >
                        </td>
                        <td>
                            <input
                                type="text"
                                name="grades[${student.id}][note]"
                                class="form-control"
                                value="${noteValue}"
                            >
                        </td>
                    </tr>
                `);
            });

            saveBtn.disabled = false;
        } catch (error) {
            resetTable(@json(__('student_grades.load_failed')));
            showAlert('danger', error.message || @json(__('student_grades.load_failed')));
        } finally {
            loadBtn.disabled = false;
            loadBtn.textContent = @json(__('student_grades.load_students'));
        }
    });
});
</script>
@endpush