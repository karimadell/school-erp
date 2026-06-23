@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">👨‍🏫 {{ __('teachers.title') }}</h3>
            <small class="text-muted">{{ __('teachers.subtitle') }}</small>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('dashboard.teachers.print') }}" target="_blank" class="btn btn-dark">
                🖨 {{ __('teachers.print') }}
            </a>

            <a href="{{ route('dashboard.teachers.pdf') }}" class="btn btn-danger">
                📄 {{ __('teachers.pdf') }}
            </a>

            <a href="{{ route('dashboard.teachers.excel') }}" class="btn btn-success">
                📊 Excel
            </a>

            <a href="{{ route('dashboard.teachers.create') }}" class="btn btn-primary">
                + {{ __('teachers.create') }}
            </a>
        </div>
    </div>

    {{-- Alerts --}}
    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body">
            <form method="GET" action="{{ route('dashboard.teachers.index') }}" class="row g-2">

                <div class="col-md-5">
                    <input type="text"
                           name="q"
                           value="{{ request('q') }}"
                           class="form-control"
                           placeholder="🔍 {{ __('teachers.search') }}">
                </div>

                <div class="col-md-3">
                    <select name="specialization" class="form-select">
                        <option value="">{{ __('teachers.all_specializations') }}</option>

                        @foreach($specializations as $specialization)
                            <option value="{{ $specialization }}" @selected(request('specialization') == $specialization)>
                                {{ $specialization }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">{{ __('teachers.all_statuses') }}</option>
                        <option value="1" @selected(request('status') === '1')>
                            {{ __('teachers.active') }}
                        </option>
                        <option value="0" @selected(request('status') === '0')>
                            {{ __('teachers.inactive') }}
                        </option>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary w-100">
                        {{ __('teachers.filter') }}
                    </button>

                    <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-outline-secondary">
                        {{ __('teachers.reset') }}
                    </a>
                </div>

            </form>
        </div>
    </div>

    {{-- Stats --}}
    <div class="row g-3 mb-3">

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('teachers.total_teachers') }}</div>
                    <h3 class="mb-0">{{ $teachers->count() }}</h3>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('teachers.active_teachers') }}</div>
                    <h3 class="mb-0">{{ $teachers->where('is_active', true)->count() }}</h3>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('teachers.inactive_teachers') }}</div>
                    <h3 class="mb-0">{{ $teachers->where('is_active', false)->count() }}</h3>
                </div>
            </div>
        </div>

    </div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>👤 {{ __('teachers.full_name') }}</th>
                            <th>✂ {{ __('teachers.short_name') }}</th>
                            <th>📚 {{ __('teachers.subjects') }}</th>
                            <th>🎯 {{ __('teachers.specialization') }}</th>
                            <th>📞 {{ __('teachers.phone') }}</th>
                            <th>📅 {{ __('teachers.hire_date') }}</th>
                            <th>⚡ {{ __('teachers.status') }}</th>
                            <th class="text-end">⚙ {{ __('teachers.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($teachers as $teacher)
                            <tr>
                                <td>{{ $teacher->id }}</td>

                                <td class="fw-bold">{{ $teacher->full_name }}</td>

                                <td>
                                    <span class="badge bg-light text-dark">
                                        {{ $teacher->short_name }}
                                    </span>
                                </td>

                                <td>
                                    @forelse($teacher->subjects as $subject)
                                        <span class="badge bg-primary-subtle text-dark mb-1">
                                            {{ $subject->name_ru }}
                                        </span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>

                                <td>{{ $teacher->specialization ?? '—' }}</td>
                                <td>{{ $teacher->phone ?? '—' }}</td>
                                <td>{{ $teacher->hire_date?->format('Y-m-d') ?? '—' }}</td>

                                <td>
                                    @if($teacher->is_active)
                                        <span class="badge bg-success">
                                            {{ __('teachers.active') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">
                                            {{ __('teachers.inactive') }}
                                        </span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    <div class="btn-group">

                                        {{-- 👁 VIEW --}}
                                        <a href="{{ route('dashboard.teachers.show', $teacher->id) }}"
                                           class="btn btn-sm btn-dark"
                                           title="{{ __('teachers.view') }}">
                                            👁
                                        </a>

                                        {{-- 📎 DOCUMENTS --}}
                                        <a href="{{ route('dashboard.teachers.documents', $teacher->id) }}"
                                           class="btn btn-sm btn-info">
                                            📎
                                        </a>

                                        {{-- ✏ EDIT --}}
                                        <a href="{{ route('dashboard.teachers.edit', $teacher->id) }}"
                                           class="btn btn-sm btn-warning">
                                            ✏
                                        </a>

                                        {{-- 🗑 DELETE --}}
                                        <form action="{{ route('dashboard.teachers.destroy', $teacher->id) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('{{ __('teachers.confirm_delete') }}')">
                                            @csrf
                                            @method('DELETE')

                                            <button class="btn btn-sm btn-danger">
                                                🗑
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    {{ __('teachers.no_data') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

            </div>
        </div>
    </div>

</div>

@endsection