@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">🕘 {{ __('enrollments.history') }}</h3>
            <div class="text-muted">{{ $student->full_name }}</div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.enrollments.create', $student->id) }}" class="btn btn-success">
                + {{ __('enrollments.create') }}
            </a>

            <a href="{{ route('dashboard.students.show', $student->id) }}" class="btn btn-secondary">
                {{ __('enrollments.back') }}
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-primary text-white fw-bold">
            {{ __('enrollments.title') }}
        </div>

        <div class="card-body p-0">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>{{ __('enrollments.academic_year') }}</th>
                        <th>{{ __('enrollments.stage') }}</th>
                        <th>{{ __('enrollments.grade') }}</th>
                        <th>{{ __('enrollments.class') }}</th>
                        <th>{{ __('enrollments.enrollment_date') }}</th>
                        <th>{{ __('enrollments.status') }}</th>
                        <th>{{ __('enrollments.notes') }}</th>
                        <th width="160">{{ __('classes.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($enrollments as $enrollment)
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
                            <td>{{ $enrollment->id }}</td>
                            <td>{{ $enrollment->academicYear->name ?? $enrollment->academic_year ?? '-' }}</td>
                            <td>{{ $enrollment->stage->name ?? '-' }}</td>
                            <td>{{ $enrollment->grade->name ?? '-' }}</td>
                            <td>{{ $enrollment->schoolClass->name ?? '-' }}</td>
                            <td>{{ optional($enrollment->date)->format('Y-m-d') ?? '-' }}</td>
                            <td>
                                <span class="badge bg-{{ $statusClass }}">
                                    {{ $enrollment->status_label }}
                                </span>
                            </td>
                            <td>{{ $enrollment->notes ?? '-' }}</td>
                            <td>
                                <a href="{{ route('dashboard.enrollments.edit', $enrollment->id) }}"
                                   class="btn btn-sm btn-warning">
                                    {{ __('enrollments.edit') }}
                                </a>

                                <form action="{{ route('dashboard.enrollments.destroy', $enrollment->id) }}"
                                      method="POST"
                                      class="d-inline"
                                      onsubmit="return confirm('{{ __('enrollments.confirm_delete') }}')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-danger">
                                        {{ __('enrollments.delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                {{ __('enrollments.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($enrollments, 'links'))
            <div class="card-footer">
                {{ $enrollments->links() }}
            </div>
        @endif
    </div>

</div>

@endsection