@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">
                📅 {{ __('timetable.schedule_for') }}: {{ $class->name_ru ?? $class->code }}
            </h3>

            <small class="text-muted">
                {{ $class->grade->stage->name ?? '' }}
                @if($class->grade?->stage) / @endif
                {{ $class->grade->name ?? '' }}
            </small>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.timetable.create') }}" class="btn btn-primary">
                + {{ __('timetable.add_lesson') }}
            </a>

            <a href="{{ route('dashboard.timetable.pdf', $class->id) }}" class="btn btn-danger">
                📄 {{ __('timetable.pdf') }}
            </a>

            <a href="{{ route('dashboard.timetable.index') }}" class="btn btn-outline-secondary">
                ← {{ __('timetable.back') }}
            </a>
        </div>
    </div>

    {{-- Alerts --}}
    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

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

    <div id="timetableAlert" class="alert d-none shadow-sm border-0"></div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between">
            <span>{{ __('timetable.weekly_schedule') }}</span>
            <small class="text-white-50">{{ __('timetable.drag_hint') }}</small>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-bordered align-middle mb-0 timetable-table">

                    <thead class="table-light">
                        <tr>
                            <th width="160">{{ __('timetable.period') }}</th>
                            @foreach($days as $day)
                                <th class="text-center">{{ $day->name_ru ?? $day->name }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($periods as $period)
                            <tr>
                                <td class="fw-bold bg-light">
                                    {{ __('timetable.lesson') }} {{ $period->number }}
                                    <div class="text-muted small">
                                        {{ $period->start_time }} - {{ $period->end_time }}
                                    </div>
                                </td>

                                @foreach($days as $day)
                                    @php
                                        $cellKey = $day->id . '_' . $period->id;
                                        $lesson = $timetable->get($cellKey);
                                    @endphp

                                    <td class="timetable-cell drop-zone text-center"
                                        data-day-id="{{ $day->id }}"
                                        data-period-id="{{ $period->id }}">

                                        @if($lesson)
                                            <div class="lesson-card"
                                                 draggable="true"
                                                 data-lesson-id="{{ $lesson->id }}">

                                                <div class="drag-handle">⠿</div>

                                                <div class="fw-bold text-primary">
                                                    {{ $lesson->subject->name_ru ?? '-' }}
                                                </div>

                                                <div class="small mt-1">
                                                    👨‍🏫 {{ $lesson->teacher->short_name ?? '-' }}
                                                </div>

                                                @if($lesson->room)
                                                    <div class="small text-muted mt-1">
                                                        🏫 {{ $lesson->room }}
                                                    </div>
                                                @endif

                                                <div class="mt-2 d-flex justify-content-center gap-1">
                                                    <a href="{{ route('dashboard.timetable.edit', $lesson->id) }}"
                                                       class="btn btn-sm btn-warning">✏</a>

                                                    <form method="POST"
                                                          action="{{ route('dashboard.timetable.destroy', $lesson->id) }}"
                                                          onsubmit="return confirm('{{ __('timetable.confirm_delete') }}')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="btn btn-sm btn-danger">🗑</button>
                                                    </form>
                                                </div>
                                            </div>
                                        @else
                                            <div class="empty-cell">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary open-add-modal"
                                                        data-class="{{ $class->id }}"
                                                        data-day="{{ $day->id }}"
                                                        data-period="{{ $period->id }}">
                                                    + {{ __('timetable.add') }}
                                                </button>
                                            </div>
                                        @endif

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

{{-- Quick Add Modal --}}
<div class="modal fade" id="quickAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" action="{{ route('dashboard.timetable.store') }}" class="modal-content border-0 shadow">
            @csrf

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">➕ {{ __('timetable.add_lesson') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <input type="hidden" name="class_id" id="modal_class_id">
                <input type="hidden" name="day_id" id="modal_day_id">
                <input type="hidden" name="period_id" id="modal_period_id">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('timetable.subject') }}</label>

                        <select name="subject_id" id="modal_subject_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_subject') }}</option>
                            @foreach(\App\Models\Subject::orderBy('name_ru')->get() as $subject)
                                <option value="{{ $subject->id }}">
                                    {{ $subject->name_ru ?? $subject->name ?? ('#' . $subject->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('timetable.teacher') }}</label>

                        <select name="teacher_id" id="modal_teacher_id" class="form-select" required>
                            <option value="">{{ __('timetable.select_teacher') }}</option>
                            @foreach(\App\Models\Teacher::where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get() as $teacher)
                                <option value="{{ $teacher->id }}">
                                    {{ $teacher->short_name }} — {{ $teacher->specialization ?? __('timetable.no_specialization') }}
                                </option>
                            @endforeach
                        </select>

                        <div class="text-muted small mt-1" id="teacherSmartHint">
                            {{ __('timetable.teacher_conflict_hint') }}
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('timetable.room') }}</label>
                        <input type="text" name="room" class="form-control" placeholder="101">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-semibold">{{ __('timetable.notes') }}</label>
                        <input type="text"
                               name="notes"
                               class="form-control"
                               placeholder="{{ __('timetable.notes_placeholder') }}">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    {{ __('timetable.cancel') }}
                </button>

                <button class="btn btn-success">
                    💾 {{ __('timetable.save') }}
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    .timetable-cell {
        min-width: 190px;
        height: 155px;
        background: #fafafa;
        transition: 0.2s ease-in-out;
        vertical-align: middle;
    }

    .timetable-cell:hover {
        background: #f3f7ff;
    }

    .lesson-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        cursor: grab;
        transition: 0.2s ease-in-out;
    }

    .lesson-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.10);
    }

    .lesson-card.dragging {
        opacity: 0.5;
        transform: scale(0.97);
    }

    .drop-zone.drag-over {
        background: #e8f1ff;
        outline: 2px dashed #0d6efd;
        outline-offset: -6px;
    }

    .drag-handle {
        font-size: 11px;
        color: #6c757d;
        text-align: left;
        margin-bottom: 6px;
    }

    .empty-cell {
        color: #9ca3af;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let draggedCard = null;
    let originalCell = null;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const subjectSelect = document.getElementById('modal_subject_id');
    const teacherSelect = document.getElementById('modal_teacher_id');
    const teacherSmartHint = document.getElementById('teacherSmartHint');

    function showAlert(type, message) {
        const alertBox = document.getElementById('timetableAlert');

        alertBox.className = 'alert shadow-sm border-0 alert-' + type;
        alertBox.innerText = message;
        alertBox.classList.remove('d-none');

        setTimeout(() => {
            alertBox.classList.add('d-none');
        }, 4000);
    }

    function bindCards() {
        document.querySelectorAll('.lesson-card').forEach(card => {
            card.ondragstart = function () {
                draggedCard = card;
                originalCell = card.closest('.drop-zone');
                this.classList.add('dragging');
            };

            card.ondragend = function () {
                this.classList.remove('dragging');
            };
        });
    }

    function bindModal() {
        document.querySelectorAll('.open-add-modal').forEach(btn => {
            btn.onclick = function () {
                document.getElementById('modal_class_id').value = this.dataset.class;
                document.getElementById('modal_day_id').value = this.dataset.day;
                document.getElementById('modal_period_id').value = this.dataset.period;

                if (subjectSelect) {
                    subjectSelect.value = '';
                }

                if (teacherSelect) {
                    teacherSelect.innerHTML = `<option value="">{{ __('timetable.select_teacher') }}</option>`;
                }

                const modal = new bootstrap.Modal(document.getElementById('quickAddModal'));
                modal.show();
            };
        });
    }

    function bindDropZones() {
        document.querySelectorAll('.drop-zone').forEach(zone => {
            zone.ondragover = function (event) {
                event.preventDefault();
                this.classList.add('drag-over');
            };

            zone.ondragleave = function () {
                this.classList.remove('drag-over');
            };

            zone.ondrop = function (event) {
                event.preventDefault();
                this.classList.remove('drag-over');

                if (!draggedCard || !originalCell) return;

                if (this.querySelector('.lesson-card')) {
                    showAlert('danger', '{{ __('timetable.cell_conflict') }}');
                    return;
                }

                fetch(`/dashboard/timetable/${draggedCard.dataset.lessonId}/move`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        day_id: this.dataset.dayId,
                        period_id: this.dataset.periodId
                    })
                })
                .then(async response => {
                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message || '{{ __('timetable.move_error') }}');
                    }

                    return data;
                })
                .then(data => {
                    const emptyCell = this.querySelector('.empty-cell');
                    if (emptyCell) emptyCell.remove();

                    this.appendChild(draggedCard);

                    originalCell.innerHTML = `
                        <div class="empty-cell">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary open-add-modal"
                                    data-class="{{ $class->id }}"
                                    data-day="${originalCell.dataset.dayId}"
                                    data-period="${originalCell.dataset.periodId}">
                                + {{ __('timetable.add') }}
                            </button>
                        </div>
                    `;

                    showAlert('success', data.message || '{{ __('timetable.moved_success') }}');

                    draggedCard = null;
                    originalCell = null;

                    bindCards();
                    bindModal();
                })
                .catch(error => {
                    showAlert('danger', error.message || '{{ __('timetable.move_error') }}');
                });
            };
        });
    }

    if (subjectSelect) {
        subjectSelect.addEventListener('change', function () {
            const subjectId = this.value;

            teacherSelect.innerHTML = `<option value="">{{ __('timetable.loading') }}</option>`;

            if (!subjectId) {
                teacherSelect.innerHTML = `<option value="">{{ __('timetable.select_teacher') }}</option>`;
                return;
            }

            fetch(`/dashboard/timetable/subject/${subjectId}/teachers`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                teacherSelect.innerHTML = `<option value="">{{ __('timetable.select_teacher') }}</option>`;

                data.teachers.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.id;
                    option.textContent = teacher.name;
                    teacherSelect.appendChild(option);
                });

                if (data.teachers.length > 0) {
                    teacherSmartHint.innerText = '{{ __('timetable.smart_teachers_loaded') }}';
                } else {
                    teacherSmartHint.innerText = '{{ __('timetable.no_teachers_for_subject') }}';
                }
            })
            .catch(() => {
                teacherSelect.innerHTML = `<option value="">{{ __('timetable.select_teacher') }}</option>`;
                teacherSmartHint.innerText = '{{ __('timetable.move_error') }}';
            });
        });
    }

    bindCards();
    bindModal();
    bindDropZones();
});
</script>

@endsection