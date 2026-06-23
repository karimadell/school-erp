@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">📅 {{ __('timetable.title') }}</h3>
            <small class="text-muted">{{ __('timetable.subtitle') }}</small>
        </div>

        <a href="{{ route('dashboard.timetable.create') }}" class="btn btn-primary">
            + {{ __('timetable.add_lesson') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white fw-bold">
            {{ __('timetable.classes_list') }}
        </div>

        <div class="card-body">
            <div class="row g-3">

                @forelse($classes as $class)
                    <div class="col-md-3">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body">

                                <div class="fw-bold fs-5 mb-2">
                                    {{ $class->name_ru ?? $class->code }}
                                </div>

                                <div class="text-muted small mb-2">
                                    {{ $class->grade->stage->name ?? '' }}
                                    @if($class->grade?->stage) / @endif
                                    {{ $class->grade->name ?? '' }}
                                </div>

                                <div class="mb-3">
                                    <span class="badge bg-primary">
                                        {{ $class->code ?? '-' }}
                                    </span>
                                </div>

                                <a href="{{ route('dashboard.timetable.show', $class->id) }}"
                                   class="btn btn-outline-primary w-100">
                                    👁 {{ __('timetable.view_schedule') }}
                                </a>

                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center text-muted py-5">
                            {{ __('timetable.no_classes') }}
                        </div>
                    </div>
                @endforelse

            </div>
        </div>
    </div>

</div>

@endsection