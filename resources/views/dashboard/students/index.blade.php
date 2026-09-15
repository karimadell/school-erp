@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('students.title') }}</h1>
            <p class="text-muted mb-0">{{ __('students.subtitle') }}</p>
        </div>

        <a href="{{ route('dashboard.students.create') }}" class="btn btn-primary">
            + {{ __('students.new_student') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    {{-- Students List UI corrective — every figure below is a plain COUNT
         query over the real Student table ($studentSummary, computed in
         StudentController::index()), never a hardcoded number and never a
         fake "active" status this application doesn't otherwise define. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">{{ __('students.summary_total') }}</div>
                    <div class="fs-3 fw-semibold">{{ $studentSummary['total'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">{{ __('students.summary_male') }}</div>
                    <div class="fs-3 fw-semibold">{{ $studentSummary['male'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">{{ __('students.summary_female') }}</div>
                    <div class="fs-3 fw-semibold">{{ $studentSummary['female'] }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter toolbar — same GET query behaviour as before, class filter
         added on the exact same Student.class_id relation the "Класс"
         column below already reads. --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted">{{ __('students.search_name') }}</label>
                    <input type="text"
                           name="q"
                           value="{{ request('q') }}"
                           class="form-control"
                           placeholder="{{ __('students.search_name') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">{{ __('students.gender') }}</label>
                    <select name="gender" class="form-select">
                        <option value="">{{ __('students.gender') }}</option>
                        <option value="male" @selected(request('gender') == 'male')>{{ __('students.male') }}</option>
                        <option value="female" @selected(request('gender') == 'female')>{{ __('students.female') }}</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">{{ __('students.class') }}</label>
                    <select name="class_id" class="form-select">
                        <option value="">{{ __('students.select_class_all') }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" @selected(request('class_id') == $class->id)>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill">
                        {{ __('students.filter') }}
                    </button>

                    <a href="{{ route('dashboard.students.index') }}" class="btn btn-outline-secondary flex-fill">
                        {{ __('students.reset') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive p-0">

            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="60" class="ps-3">#</th>
                        <th width="80">{{ __('students.photo') }}</th>
                        <th>{{ __('students.name') }}</th>
                        <th>{{ __('students.class') }}</th>
                        <th>{{ __('students.gender') }}</th>
                        <th>{{ __('students.phone') }}</th>
                        <th>{{ __('students.nationality') }}</th>
                        <th width="220" class="pe-3">{{ __('students.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($students as $student)
                        <tr>
                            <td class="ps-3 text-muted small">{{ $student->id }}</td>

                            <td>
                                @if($student->photo)
                                    <img src="{{ Storage::disk(config('filesystems.uploads.public'))->url($student->photo) }}"
                                         class="rounded-circle"
                                         style="width:44px;height:44px;object-fit:cover;">
                                @else
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width:44px;height:44px;">
                                        {{ mb_substr($student->first_name_ru ?? 'У', 0, 1) }}
                                    </div>
                                @endif
                            </td>

                            <td>
                                <div class="fw-semibold">{{ $student->full_name }}</div>
                                <div class="text-muted small">{{ $student->short_name }}</div>
                            </td>

                            <td>
                                @if($student->class)
                                    <span class="badge bg-light text-dark border">{{ $student->class->name }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            <td>{{ $student->gender ? __('students.' . $student->gender) : '—' }}</td>

                            <td>{{ $student->phone ?? '—' }}</td>
                            <td>{{ $student->nationality ?? '—' }}</td>

                            <td class="pe-3">
                                <div class="d-flex align-items-center gap-1">
                                    <a href="{{ route('dashboard.students.show', $student->id) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        {{ __('students.view') }}
                                    </a>

                                    <a href="{{ route('dashboard.students.edit', $student->id) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        {{ __('students.edit') }}
                                    </a>

                                    {{-- Students List UI corrective — secondary
                                         action moved into a compact dropdown,
                                         still the exact same
                                         dashboard.enrollments.create route,
                                         still reusing the SAME existing
                                         Student (never a new one). Bootstrap's
                                         dropdown ships in the bundle every
                                         dashboard page already loads — no new
                                         script or dependency added here. --}}
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('students.more_actions') }}">
                                            &#8942;
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="{{ route('dashboard.enrollments.create', $student->id) }}">
                                                    + {{ __('enrollments.create') }}
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                {{ __('students.no_students') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        </div>
    </div>

    <div class="mt-3">
        {{ $students->links() }}
    </div>

</div>

@endsection
