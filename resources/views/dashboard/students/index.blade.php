@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">🎓 {{ __('students.title') }}</h3>

        <a href="{{ route('dashboard.students.create') }}" class="btn btn-success">
            + {{ __('students.new_student') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-4">
            <input type="text"
                   name="q"
                   value="{{ request('q') }}"
                   class="form-control"
                   placeholder="{{ __('students.search_name') }}">
        </div>

        <div class="col-md-3">
            <select name="gender" class="form-select">
                <option value="">{{ __('students.gender') }}</option>
                <option value="male" @selected(request('gender') == 'male')>{{ __('students.male') }}</option>
                <option value="female" @selected(request('gender') == 'female')>{{ __('students.female') }}</option>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary">
                {{ __('students.filter') }}
            </button>

            <a href="{{ route('dashboard.students.index') }}" class="btn btn-outline-secondary">
                {{ __('students.reset') }}
            </a>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">

            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="70">#</th>
                        <th width="90">{{ __('students.photo') }}</th>
                        <th>{{ __('students.name') }}</th>
                        <th>{{ __('students.class') }}</th>
                        <th>{{ __('students.gender') }}</th>
                        <th>{{ __('students.phone') }}</th>
                        <th>{{ __('students.nationality') }}</th>
                        <th width="260">{{ __('students.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($students as $student)
                        <tr>
                            <td>{{ $student->id }}</td>

                            <td>
                                @if($student->photo)
                                    <img src="{{ asset('storage/' . $student->photo) }}"
                                         class="rounded-circle"
                                         style="width:50px;height:50px;object-fit:cover;">
                                @else
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width:50px;height:50px;">
                                        {{ mb_substr($student->first_name_ru ?? 'У', 0, 1) }}
                                    </div>
                                @endif
                            </td>

                            <td>
                                <strong>{{ $student->full_name }}</strong>
                                <div class="text-muted small">{{ $student->short_name }}</div>
                            </td>

                            <td>{{ $student->class->name ?? '-' }}</td>

                            <td>{{ $student->gender ? __('students.' . $student->gender) : '-' }}</td>

                            <td>{{ $student->phone ?? '-' }}</td>
                            <td>{{ $student->nationality ?? '-' }}</td>

                            <td>
                                <a href="{{ route('dashboard.students.show', $student->id) }}"
                                   class="btn btn-sm btn-outline-primary">
                                    {{ __('students.view') }}
                                </a>

                                <a href="{{ route('dashboard.students.edit', $student->id) }}"
                                   class="btn btn-sm btn-warning">
                                    {{ __('students.edit') }}
                                </a>

                                <a href="{{ route('dashboard.enrollments.create', $student->id) }}"
                                   class="btn btn-sm btn-success">
                                    + {{ __('enrollments.create') }}
                                </a>
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

            <div class="mt-3">
                {{ $students->links() }}
            </div>

        </div>
    </div>

</div>

@endsection