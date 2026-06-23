@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">📚 {{ __('grades.title') }}</h3>

        <a href="{{ route('dashboard.grades.create') }}" class="btn btn-primary">
            + {{ __('grades.create') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">

            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('grades.name') }}</th>
                        <th>{{ __('grades.stage') }}</th>
                        <th>{{ __('grades.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($grades as $grade)
                        <tr>
                            <td>{{ $grade->id }}</td>
                            <td>{{ $grade->name }}</td>
                            <td>{{ $grade->stage->name ?? '-' }}</td>
                            <td>
                                <a href="{{ route('dashboard.grades.edit', $grade->id) }}" class="btn btn-sm btn-warning">
                                    {{ __('grades.edit') }}
                                </a>

                                <form action="{{ route('dashboard.grades.destroy', $grade->id) }}"
                                      method="POST"
                                      class="d-inline"
                                      onsubmit="return confirm('{{ __('grades.confirm_delete') }}')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-danger">
                                        {{ __('grades.delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                {{ __('grades.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>

@endsection