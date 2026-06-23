@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">👤 {{ $student->full_name }}</h3>
            <div class="text-muted">{{ $student->short_name }}</div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.enrollments.create', $student->id) }}" class="btn btn-success">
                + {{ __('enrollments.create') }}
            </a>

            <a href="{{ route('dashboard.students.edit', $student->id) }}" class="btn btn-warning">
                ✏️ {{ __('students.edit') }}
            </a>

            <a href="{{ route('dashboard.students.index') }}" class="btn btn-secondary">
                {{ __('students.back') }}
            </a>
        </div>
    </div>

    <div class="row g-4">

        {{-- Left Profile Card --}}
        <div class="col-md-4">

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body text-center">

                    @if($student->photo)
                        <img src="{{ asset('storage/' . $student->photo) }}"
                             class="rounded-circle mb-3"
                             style="width:180px;height:180px;object-fit:cover;border:5px solid #f1f1f1;">
                    @else
                        <div class="bg-primary text-white rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center"
                             style="width:180px;height:180px;font-size:70px;">
                            {{ mb_substr($student->first_name_ru ?? 'У', 0, 1) }}
                        </div>
                    @endif

                    <h5 class="fw-bold mb-1">{{ $student->full_name }}</h5>
                    <div class="text-muted mb-3">{{ $student->short_name }}</div>

                    @if($currentEnrollment)
                        <span class="badge bg-success mb-3">
                            {{ __('enrollments.status_active') }}
                        </span>
                    @else
                        <span class="badge bg-secondary mb-3">
                            {{ __('enrollments.no_data') }}
                        </span>
                    @endif

                    <hr>

                    <div class="text-start">
                        <p><strong>{{ __('students.class') }}:</strong> {{ $student->class->name ?? '-' }}</p>
                        <p><strong>{{ __('students.gender') }}:</strong> {{ $student->gender ? __('students.' . $student->gender) : '-' }}</p>
                        <p><strong>{{ __('students.birth_date') }}:</strong> {{ optional($student->birth_date)->format('Y-m-d') ?? '-' }}</p>
                        <p><strong>{{ __('students.nationality') }}:</strong> {{ $student->nationality ?? '-' }}</p>
                    </div>

                </div>
            </div>

            {{-- Contact --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white fw-bold">
                    {{ __('students.address') }}
                </div>

                <div class="card-body">
                    <p><strong>{{ __('students.phone') }}:</strong> {{ $student->phone ?? '-' }}</p>
                    <p><strong>{{ __('students.email') }}:</strong> {{ $student->email ?? '-' }}</p>
                    <p><strong>{{ __('students.address') }}:</strong> {{ $student->address ?? '-' }}</p>
                </div>
            </div>

        </div>

        {{-- Right Content --}}
        <div class="col-md-8">

            {{-- Current Enrollment --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white fw-bold">
                    🎓 {{ __('enrollments.title') }}
                </div>

                <div class="card-body">
                    @if($currentEnrollment)
                        <div class="row g-3">
                            <div class="col-md-6">
                                <strong>{{ __('enrollments.academic_year') }}</strong>
                                <div>{{ $currentEnrollment->academicYear->name ?? $currentEnrollment->academic_year ?? '-' }}</div>
                            </div>

                            <div class="col-md-6">
                                <strong>{{ __('enrollments.enrollment_date') }}</strong>
                                <div>{{ optional($currentEnrollment->date)->format('Y-m-d') ?? '-' }}</div>
                            </div>

                            <div class="col-md-4">
                                <strong>{{ __('enrollments.stage') }}</strong>
                                <div>{{ $currentEnrollment->stage->name ?? '-' }}</div>
                            </div>

                            <div class="col-md-4">
                                <strong>{{ __('enrollments.grade') }}</strong>
                                <div>{{ $currentEnrollment->grade->name ?? '-' }}</div>
                            </div>

                            <div class="col-md-4">
                                <strong>{{ __('enrollments.class') }}</strong>
                                <div>{{ $currentEnrollment->schoolClass->name ?? '-' }}</div>
                            </div>
                        </div>
                    @else
                        <div class="text-muted">
                            {{ __('enrollments.no_data') }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Student Data --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-info text-white fw-bold">
                    {{ __('students.student_data') }}
                </div>

                <div class="card-body">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <strong>{{ __('students.last_name_ru') }}</strong>
                            <div>{{ $student->last_name_ru ?? '-' }}</div>
                        </div>

                        <div class="col-md-4">
                            <strong>{{ __('students.first_name_ru') }}</strong>
                            <div>{{ $student->first_name_ru ?? '-' }}</div>
                        </div>

                        <div class="col-md-4">
                            <strong>{{ __('students.patronymic_ru') }}</strong>
                            <div>{{ $student->patronymic_ru ?? '-' }}</div>
                        </div>

                        <div class="col-md-6">
                            <strong>{{ __('students.first_name_ar') }}</strong>
                            <div>{{ $student->first_name ?? '-' }}</div>
                        </div>

                        <div class="col-md-6">
                            <strong>{{ __('students.last_name_ar') }}</strong>
                            <div>{{ $student->last_name ?? '-' }}</div>
                        </div>

                    </div>
                </div>
            </div>

            {{-- Documents --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-secondary text-white fw-bold">
                    📁 {{ __('students.documents') }}
                </div>

                <div class="card-body">
                    @if(!empty($student->documents))
                        @foreach((array) $student->documents as $document)
                            <a href="{{ asset('storage/' . $document) }}"
                               target="_blank"
                               class="btn btn-sm btn-outline-primary mb-1">
                                📄 {{ basename($document) }}
                            </a>
                        @endforeach
                    @else
                        <div class="text-muted">{{ __('students.no_documents') }}</div>
                    @endif
                </div>
            </div>

            {{-- Enrollment History --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-warning fw-bold">
                    🕘 {{ __('enrollments.title') }}
                </div>

                <div class="card-body p-0">
                    <table class="table table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('enrollments.academic_year') }}</th>
                                <th>{{ __('enrollments.stage') }}</th>
                                <th>{{ __('enrollments.grade') }}</th>
                                <th>{{ __('enrollments.class') }}</th>
                                <th>{{ __('enrollments.status') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($student->enrollments as $enrollment)
                                @php
                                    $statusClass = match($enrollment->status) {
                                        'active' => 'success',
                                        'transferred' => 'warning',
                                        'withdrawn' => 'danger',
                                        'graduated' => 'primary',
                                        default => 'secondary',
                                    };
                                @endphp

                                <tr>
                                    <td>{{ $enrollment->academicYear->name ?? $enrollment->academic_year ?? '-' }}</td>
                                    <td>{{ $enrollment->stage->name ?? '-' }}</td>
                                    <td>{{ $enrollment->grade->name ?? '-' }}</td>
                                    <td>{{ $enrollment->schoolClass->name ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $statusClass }}">
                                            {{ $enrollment->status_label }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">
                                        {{ __('enrollments.no_data') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Grades --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white fw-bold">
                    📊 {{ __('students.grades') }}
                </div>

                <div class="card-body p-0">
                    <table class="table table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('students.subject') }}</th>
                                <th>{{ __('students.exam') }}</th>
                                <th>{{ __('students.score') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($student->grades as $grade)
                                <tr>
                                    <td>{{ $grade->subject->name_ru ?? $grade->subject->name ?? '-' }}</td>
                                    <td>{{ $grade->exam->name ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-primary">
                                            {{ $grade->score }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted">
                                        {{ __('students.no_grades') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

@endsection