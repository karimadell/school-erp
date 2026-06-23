@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">📚 {{ __('subjects.title') }}</h3>
            <div class="text-muted">{{ __('subjects.list_hint') }}</div>
        </div>

        <a href="{{ route('dashboard.subjects.create') }}" class="btn btn-primary">
            + {{ __('subjects.create') }}
        </a>
    </div>

    {{-- Success Toast --}}
    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0 d-flex align-items-center">
            <span class="me-2">✅</span>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold bg-light d-flex justify-content-between align-items-center">
            <span>{{ __('subjects.list') }}</span>

            <span class="badge bg-secondary">
                {{ $subjects->count() }} {{ __('subjects.total') }}
            </span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light text-center">
                        <tr>
                            <th width="70">ID</th>
                            <th width="150">{{ __('subjects.code') }}</th>
                            <th>{{ __('subjects.name_ru') }}</th>
                            <th width="150">{{ __('subjects.status') }}</th>
                            <th width="220">{{ __('subjects.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($subjects as $subject)
                            <tr>

                                {{-- ID --}}
                                <td class="text-center fw-bold">
                                    {{ $subject->id }}
                                </td>

                                {{-- CODE --}}
                                <td class="text-center">
                                    <span class="badge bg-dark">
                                        {{ $subject->code ?? '-' }}
                                    </span>
                                </td>

                                {{-- NAME --}}
                                <td>
                                    <strong>{{ $subject->name_ru ?? '-' }}</strong>
                                </td>

                                {{-- STATUS --}}
                                <td class="text-center">
                                    @if($subject->is_active)
                                        <span class="badge bg-success px-3">
                                            {{ __('subjects.active') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary px-3">
                                            {{ __('subjects.inactive') }}
                                        </span>
                                    @endif
                                </td>

                                {{-- ACTIONS --}}
                                <td class="text-center">

                                    <a href="{{ route('dashboard.subjects.edit', $subject->id) }}"
                                       class="btn btn-sm btn-warning">
                                        ✏️ {{ __('subjects.edit') }}
                                    </a>

                                    <form action="{{ route('dashboard.subjects.destroy', $subject->id) }}"
                                          method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('{{ __('subjects.confirm_delete') }}')">
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="btn btn-sm btn-danger">
                                            🗑 {{ __('subjects.delete') }}
                                        </button>
                                    </form>

                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    📭 {{ __('subjects.no_data') }}
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