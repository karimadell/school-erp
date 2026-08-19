@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">🏫 {{ __('classroom.title') }}</h3>
            <div class="text-muted">{{ __('classroom.list_hint') }}</div>
        </div>

        @can('manage timetable')
            <a href="{{ route('dashboard.classrooms.create') }}" class="btn btn-primary">
                + {{ __('classroom.create') }}
            </a>
        @endcan
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('dashboard.classrooms.index') }}" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="{{ __('classroom.search_placeholder') }}" value="{{ request('search') }}">
        </div>
        <div class="col-md-3">
            <select class="form-select" name="academic_year_id">
                <option value="">{{ __('classroom.all_academic_years') }}</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected(request('academic_year_id') == $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" name="room_type">
                <option value="">{{ __('classroom.all_room_types') }}</option>
                @foreach(\App\Models\PhysicalClassroom::TYPES as $type)
                    <option value="{{ $type }}" @selected(request('room_type') === $type)>{{ __("classroom.types.$type") }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-secondary w-100">{{ __('classroom.filter') }}</button>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('classroom.fields.code') }}</th>
                        <th>{{ __('classroom.fields.name') }}</th>
                        <th>{{ __('classroom.fields.room_type') }}</th>
                        <th>{{ __('classroom.fields.building') }}</th>
                        <th>{{ __('classroom.fields.floor') }}</th>
                        <th>{{ __('classroom.fields.capacity') }}</th>
                        <th>{{ __('classroom.fields.academic_year') }}</th>
                        <th>{{ __('classroom.fields.is_active') }}</th>
                        <th class="text-end">{{ __('classroom.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($classrooms as $classroom)
                    <tr>
                        <td><span class="badge bg-secondary">{{ $classroom->code }}</span></td>
                        <td>{{ $classroom->name }}</td>
                        <td><span class="badge bg-primary">{{ __("classroom.types.{$classroom->room_type}") }}</span></td>
                        <td>{{ $classroom->building }}</td>
                        <td>{{ $classroom->floor }}</td>
                        <td>{{ $classroom->capacity }}</td>
                        <td>{{ $classroom->academicYear?->name }}</td>
                        <td>
                            <span class="badge {{ $classroom->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $classroom->is_active ? __('classroom.active') : __('classroom.inactive') }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('manage timetable')
                                <a href="{{ route('dashboard.classrooms.edit', $classroom) }}" class="btn btn-sm btn-warning">
                                    {{ __('classroom.edit') }}
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">{{ __('classroom.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $classrooms->links() }}</div>
</div>
@endsection
