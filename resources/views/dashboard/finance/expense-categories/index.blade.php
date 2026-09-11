@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">{{ __('expenses.category_title') }}</h3>
            <div class="text-muted">{{ __('expenses.category_list_hint') }}</div>
        </div>
        <a href="{{ route('dashboard.finance.expense-categories.create') }}" class="btn btn-primary">+ {{ __('expenses.category_create') }}</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('expenses.category_name') }}</th>
                            <th>{{ __('expenses.is_active') }}</th>
                            <th>{{ __('expenses.expenses_count') }}</th>
                            <th>{{ __('expenses.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $category)
                            <tr>
                                <td>{{ $category->name }}</td>
                                <td>
                                    @if($category->is_active)
                                        <span class="badge bg-success">{{ __('expenses.is_active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">—</span>
                                    @endif
                                </td>
                                <td>{{ $category->expenses_count }}</td>
                                <td>
                                    <a href="{{ route('dashboard.finance.expense-categories.edit', $category) }}" class="btn btn-sm btn-outline-primary">{{ __('expenses.edit') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">{{ __('expenses.no_data') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $categories->links() }}
    </div>
</div>
@endsection
