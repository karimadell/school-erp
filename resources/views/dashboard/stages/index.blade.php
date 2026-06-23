@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">{{ __('stages.title') }}</h3>

        <a href="{{ route('dashboard.stages.create') }}" class="btn btn-primary">
            {{ __('stages.add_stage') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">

            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>{{ __('stages.name') }}</th>
                        <th>{{ __('stages.order') }}</th>
                        <th>{{ __('stages.status') }}</th>
                        <th class="text-end">{{ __('stages.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stages as $stage)
                        <tr>
                            <td>{{ $stage->id }}</td>
                            <td>{{ $stage->name }}</td>
                            <td>{{ $stage->order ?? '-' }}</td>
                            <td>
                                @if(isset($stage->is_active))
                                    @if($stage->is_active)
                                        <span class="badge bg-success">{{ __('stages.active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('stages.inactive') }}</span>
                                    @endif
                                @else
                                    <span class="badge bg-success">{{ __('stages.active') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('dashboard.stages.edit', $stage->id) }}" class="btn btn-sm btn-warning">
                                    {{ __('stages.edit') }}
                                </a>

                                <form action="{{ route('dashboard.stages.destroy', $stage->id) }}"
                                      method="POST"
                                      class="d-inline"
                                      onsubmit="return confirm('{{ __('stages.confirm_delete') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        {{ __('stages.delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                {{ __('stages.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        </div>
    </div>

</div>
@endsection