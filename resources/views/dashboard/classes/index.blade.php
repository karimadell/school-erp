@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">🏫 {{ __('classes.title') }}</h3>

        <a href="{{ route('dashboard.classes.create') }}" class="btn btn-primary">
            + {{ __('classes.create') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold">
            {{ __('classes.list') }}
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-bordered table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('classes.id') }}</th>
                            <th>{{ __('classes.code') }}</th>
                            <th>{{ __('classes.name') }}</th>
                            <th>{{ __('classes.grade') }}</th>
                            <th>{{ __('classes.capacity') }}</th>
                            <th>{{ __('classes.status') }}</th>
                            <th>{{ __('classes.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($classes as $class)
                            <tr>
                                <td>{{ $class->id }}</td>

                                <td>{{ $class->code ?? '-' }}</td>

                                <td>{{ $class->name_ru ?? $class->name ?? '-' }}</td>

                                <td>{{ $class->grade->name ?? '-' }}</td>

                                <td>{{ $class->capacity ?? '-' }}</td>

                                <td>
                                    @if($class->is_active)
                                        <span class="badge bg-success">
                                            {{ __('classes.active') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">
                                            {{ __('classes.inactive') }}
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <a href="{{ route('dashboard.classes.edit', $class->id) }}"
                                       class="btn btn-sm btn-warning">
                                        {{ __('classes.edit') }}
                                    </a>

                                    <form action="{{ route('dashboard.classes.destroy', $class->id) }}"
                                          method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('{{ __('classes.confirm_delete') }}')">
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="btn btn-sm btn-danger">
                                            {{ __('classes.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    {{ __('classes.no_data') }}
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