@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">{{ __('expenses.payee_title') }}</h3>
            <div class="text-muted">{{ __('expenses.payee_list_hint') }}</div>
        </div>
        <a href="{{ route('dashboard.finance.payees.create') }}" class="btn btn-primary">+ {{ __('expenses.payee_create') }}</a>
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
                            <th>{{ __('expenses.payee_name') }}</th>
                            <th>{{ __('expenses.payee_phone') }}</th>
                            <th>{{ __('expenses.is_active') }}</th>
                            <th>{{ __('expenses.expenses_count') }}</th>
                            <th>{{ __('expenses.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payees as $payee)
                            <tr>
                                <td>{{ $payee->name }}</td>
                                <td>{{ $payee->phone ?? '—' }}</td>
                                <td>
                                    @if($payee->is_active)
                                        <span class="badge bg-success">{{ __('expenses.is_active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">—</span>
                                    @endif
                                </td>
                                <td>{{ $payee->expenses_count }}</td>
                                <td>
                                    <a href="{{ route('dashboard.finance.payees.edit', $payee) }}" class="btn btn-sm btn-outline-primary">{{ __('expenses.edit') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">{{ __('expenses.no_data') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $payees->links() }}
    </div>
</div>
@endsection
