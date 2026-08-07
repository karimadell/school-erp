@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1">{{ __('mass_billing.list_title') }}</h2>
            <p class="text-muted mb-0">{{ __('mass_billing.subtitle') }}</p>
        </div>
        <a href="{{ route('dashboard.finance.mass-billing.create') }}" class="btn btn-primary">{{ __('mass_billing.actions.new') }}</a>
    </div>

    @if(session('success'))<div class="alert alert-success mt-3">{{ session('success') }}</div>@endif

    <div class="card card-body mt-3">
        @if($batches->isEmpty())
            <p class="text-muted mb-0">{{ __('mass_billing.empty.batches') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('mass_billing.fields.academic_year') }}</th>
                            <th>{{ __('mass_billing.fields.service') }}</th>
                            <th>{{ __('mass_billing.fields.issue_date') }}</th>
                            <th>{{ __('mass_billing.table.eligibility') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batches as $batch)
                            <tr>
                                <td>{{ $batch->academicYear?->name }}</td>
                                <td>{{ $batch->fee?->name_ru }}</td>
                                <td>{{ $batch->issue_date?->toDateString() }}</td>
                                <td><span class="badge bg-secondary">{{ __('mass_billing.status.'.$batch->status) }}</span></td>
                                <td class="text-end"><a href="{{ route('dashboard.finance.mass-billing.show', $batch) }}" class="btn btn-sm btn-outline-secondary">{{ __('mass_billing.show_title') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $batches->links() }}</div>
        @endif
    </div>
</div>
@endsection
