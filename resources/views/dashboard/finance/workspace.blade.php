@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">{{ __('finance_workspace.page_title') }}</h1>
        <p class="text-muted mb-0">{{ __('finance_workspace.page_subtitle') }}</p>
    </div>

    {{-- ================= Compact financial summary ================= --}}
    <div class="row g-3 mb-4">
        @foreach([
            __('finance_workspace.income_today') => ['value' => $operationalSummary['income_today'], 'accent' => 'text-success'],
            __('finance_workspace.expense_today') => ['value' => $operationalSummary['expense_today'], 'accent' => 'text-danger'],
            __('finance_workspace.net_today') => ['value' => $operationalSummary['net_today'], 'accent' => (float) $operationalSummary['net_today'] < 0 ? 'text-danger' : 'text-success'],
            __('finance_workspace.total_balance') => ['value' => $operationalSummary['total_balance'], 'accent' => ''],
        ] as $label => $card)
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">{{ $label }}</div>
                    <strong class="fs-5 {{ $card['accent'] }}">{{ number_format((float) $card['value'], 2, '.', ' ') }} EGP</strong>
                </div></div>
            </div>
        @endforeach
    </div>

    {{-- ================= Four primary actions ================= --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            @can('view invoices')
                <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-success btn-lg w-100">+ {{ __('finance_workspace.add_income') }}</a>
            @endcan
        </div>
        <div class="col-6 col-md-3">
            @can('create', \App\Models\Expense::class)
                <a href="{{ route('dashboard.finance.expenses.create') }}" class="btn btn-danger btn-lg w-100">+ {{ __('finance_workspace.add_expense') }}</a>
            @endcan
        </div>
        <div class="col-6 col-md-3">
            @canany(['manage cash', 'transfer cash', 'view cash reports'])
                <a href="{{ route('dashboard.cash.operations.index') }}" class="btn btn-outline-secondary btn-lg w-100">{{ __('finance_workspace.cash') }}</a>
            @endcanany
        </div>
        <div class="col-6 col-md-3">
            @can('view cash reports')
                <a href="{{ route('dashboard.cash.reports') }}" class="btn btn-outline-secondary btn-lg w-100">{{ __('finance_workspace.reports') }}</a>
            @endcan
        </div>
    </div>

    {{-- ================= Последние операции ================= --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-bold">{{ __('finance_workspace.recent_operations') }}</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <tbody>
                        @forelse($recentOperations as $operation)
                            @php
                                $isTransfer = $operation->category === \App\Models\CashTransaction::CATEGORY_TRANSFER;
                                $isIn = $operation->type === \App\Models\CashTransaction::TYPE_IN;
                                $badgeClass = $isTransfer ? 'bg-secondary' : ($isIn ? 'bg-success' : 'bg-danger');
                                $badgeLabel = $isTransfer ? 'Перевод' : ($isIn ? 'Приход' : 'Расход');
                            @endphp
                            <tr>
                                <td class="text-muted small" style="width: 130px;">{{ $operation->created_at->format('d.m.Y H:i') }}</td>
                                <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                                <td>{{ $operation->description ?: $operation->account?->name }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $operation->amount, 2, '.', ' ') }} EGP</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-4">{{ __('finance_workspace.no_recent_operations') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
