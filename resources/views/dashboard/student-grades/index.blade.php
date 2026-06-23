@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">📊 {{ __('student_grades.title') }}</h3>
            <div class="text-muted">{{ __('student_grades.list') }}</div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.student-grades.report.form') }}" class="btn btn-dark">
                🖨 {{ __('student_grades.print_report') }}
            </a>

            <a href="{{ route('dashboard.student-grades.bulk.form') }}" class="btn btn-primary">
                ⚡ {{ __('student_grades.bulk_entry') }}
            </a>

            <a href="{{ route('dashboard.student-grades.create') }}" class="btn btn-success">
                + {{ __('student_grades.create') }}
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">

            <table class="table table-bordered table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="70">#</th>
                        <th>{{ __('student_grades.student') }}</th>
                        <th>{{ __('student_grades.subject') }}</th>
                        <th>{{ __('student_grades.exam') }}</th>
                        <th>{{ __('student_grades.quarter') }}</th>
                        <th width="120">{{ __('student_grades.score') }}</th>
                        <th>{{ __('student_grades.note') }}</th>
                        <th width="180">{{ __('classes.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($grades as $grade)
                        <tr>
                            <td>{{ $grade->id }}</td>
                            <td>
                                <strong>{{ $grade->student->full_name ?? '-' }}</strong>
                                @if($grade->student)
                                    <div class="text-muted small">{{ $grade->student->short_name }}</div>
                                @endif
                            </td>
                            <td>{{ $grade->subject->name_ru ?? $grade->subject->name ?? '-' }}</td>
                            <td>{{ $grade->exam->name ?? '-' }}</td>
                            <td>{{ $grade->quarter->name ?? '-' }}</td>
                            <td>
                                <span class="badge bg-primary fs-6">{{ $grade->score }}</span>
                            </td>
                            <td>{{ $grade->note ?? '-' }}</td>
                            <td>
                                <a href="{{ route('dashboard.student-grades.edit', $grade->id) }}"
                                   class="btn btn-sm btn-warning">
                                    {{ __('student_grades.edit') }}
                                </a>

                                <form action="{{ route('dashboard.student-grades.destroy', $grade->id) }}"
                                      method="POST"
                                      class="d-inline"
                                      onsubmit="return confirm('{{ __('student_grades.confirm_delete') }}')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-danger">
                                        {{ __('student_grades.delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                {{ __('student_grades.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>

@endsection