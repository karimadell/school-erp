@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">{{ __('revenues.page_title') }}</h1>
            <p class="text-muted mb-0">{{ __('revenues.list_hint') }}</p>
        </div>
        <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('revenues.back') }}</a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">{{ __('revenues.status') }}</label>
                    <select name="status" class="form-select">
                        <option value="">Все</option>
                        @foreach([\App\Models\RevenueEntry::STATUS_DRAFT, \App\Models\RevenueEntry::STATUS_POSTED, \App\Models\RevenueEntry::STATUS_REVERSED] as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('revenues.status_'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('revenues.category') }}</label>
                    <select name="revenue_category_id" class="form-select">
                        <option value="">Все</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) $filters['revenue_category_id'] === $category->id)>{{ $category->name_ru }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Применить</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('revenues.reference_number') }}</th>
                            <th>{{ __('revenues.category') }}</th>
                            <th class="text-end">{{ __('revenues.amount') }}</th>
                            <th>{{ __('revenues.revenue_date') }}</th>
                            <th>{{ __('revenues.status') }}</th>
                            <th>{{ __('revenues.cash_account') }}</th>
                            <th>{{ __('revenues.created_by') }}</th>
                            <th>{{ __('revenues.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ $entry->reference_number }}</td>
                                <td>{{ $entry->category?->name_ru ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $entry->amount, 2, '.', ' ') }} EGP</td>
                                <td>{{ optional($entry->revenue_date)->format('d.m.Y') }}</td>
                                <td>@include('dashboard.finance.income.revenue._status_badge', ['status' => $entry->status])</td>
                                <td>{{ $entry->cashAccount?->name ?? '—' }}</td>
                                <td>{{ $entry->creator?->name ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('dashboard.finance.income.revenue.show', $entry) }}" class="btn btn-sm btn-outline-secondary">{{ __('revenues.view') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">{{ __('revenues.no_data') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $entries->links() }}
    </div>
</div>
@endsection
