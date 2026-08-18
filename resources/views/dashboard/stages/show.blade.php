@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="text-muted small mb-1">{{ __('stages.structure') }}</div>
            <h3 class="fw-bold mb-0">{{ $stage->name }}</h3>
        </div>

        <div class="d-flex gap-2">
            @can('manage grades')
                <a href="{{ route('dashboard.grades.create', ['stage_id' => $stage->id, 'return_to' => 'dashboard.stages.show', 'return_stage_id' => $stage->id]) }}" class="btn btn-primary">
                    {{ __('stages.add_grade') }}
                </a>
            @endcan
            <a href="{{ route('dashboard.stages.index') }}" class="btn btn-secondary">
                {{ __('stages.back_to_stages') }}
            </a>
        </div>
    </div>

    @if($activeAcademicYear)
        <div class="alert alert-light border mb-4">
            <span class="fw-semibold">{{ __('stages.academic_year') }}:</span>
            {{ $activeAcademicYear->name }}
        </div>
    @else
        <div class="alert alert-warning mb-4">
            {{ __('stages.no_active_academic_year') }}
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ __('stages.grades') }}</div>
                    <div class="fs-3 fw-bold">{{ $stage->grades_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ __('stages.school_classes') }}</div>
                    <div class="fs-3 fw-bold">{{ $stage->school_classes_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ __('stages.students') }}</div>
                    <div class="fs-3 fw-bold">{{ $stage->current_students_count }}</div>
                </div>
            </div>
        </div>
    </div>

    @forelse($stage->grades as $grade)
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center gap-3">
                <h5 class="mb-0">{{ $grade->name }}</h5>
                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    <div class="d-flex gap-3 text-muted small me-2">
                        <span>{{ __('stages.school_classes') }}: {{ $grade->classes->count() }}</span>
                        <span>{{ __('stages.students') }}: {{ $grade->current_students_count }}</span>
                    </div>
                    @can('manage classes')
                        <a href="{{ route('dashboard.classes.create', ['grade_id' => $grade->id, 'return_to' => 'dashboard.stages.show', 'return_stage_id' => $stage->id]) }}" class="btn btn-sm btn-primary">
                            {{ __('stages.add_school_class') }}
                        </a>
                    @endcan
                    @can('manage grades')
                        <a href="{{ route('dashboard.grades.edit', ['grade' => $grade->id, 'return_to' => 'dashboard.stages.show', 'return_stage_id' => $stage->id]) }}" class="btn btn-sm btn-warning">
                            {{ __('stages.edit_grade') }}
                        </a>
                        <form action="{{ route('dashboard.grades.destroy', $grade) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('stages.confirm_delete_grade') }}')">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="return_to" value="dashboard.stages.show">
                            <input type="hidden" name="return_stage_id" value="{{ $stage->id }}">
                            <button type="submit" class="btn btn-sm btn-danger">{{ __('stages.delete_grade') }}</button>
                        </form>
                    @endcan
                </div>
            </div>

            <div class="card-body p-0">
                @if($grade->classes->isEmpty())
                    <div class="text-center text-muted py-4">
                        {{ __('stages.no_school_classes') }}
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('stages.school_group') }}</th>
                                    <th>{{ __('stages.students') }}</th>
                                    @can('manage classes')
                                        <th class="text-end">{{ __('stages.actions') }}</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($grade->classes as $schoolClass)
                                    <tr>
                                        <td>{{ $schoolClass->name_ru ?: $schoolClass->code }}</td>
                                        <td>{{ $schoolClass->current_students_count }}</td>
                                        @can('manage classes')
                                            <td class="text-end">
                                                <a href="{{ route('dashboard.classes.edit', ['class' => $schoolClass->id, 'return_to' => 'dashboard.stages.show', 'return_stage_id' => $stage->id]) }}" class="btn btn-sm btn-warning">
                                                    {{ __('stages.edit_school_class') }}
                                                </a>
                                                <form action="{{ route('dashboard.classes.destroy', $schoolClass) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('stages.confirm_delete_school_class') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="return_to" value="dashboard.stages.show">
                                                    <input type="hidden" name="return_stage_id" value="{{ $stage->id }}">
                                                    <button type="submit" class="btn btn-sm btn-danger">{{ __('stages.delete_school_class') }}</button>
                                                </form>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                {{ __('stages.no_grades') }}
            </div>
        </div>
    @endforelse

</div>
@endsection
