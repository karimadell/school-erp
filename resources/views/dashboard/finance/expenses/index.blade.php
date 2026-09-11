@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">{{ __('expenses.page_title') }}</h1>
            <p class="text-muted mb-0">{{ __('expenses.list_hint') }}</p>
        </div>
        @can('create', \App\Models\Expense::class)
            <a href="{{ route('dashboard.finance.expenses.create') }}" class="btn btn-primary">
                + {{ __('expenses.create') }}
            </a>
        @endcan
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ================= Filters ================= --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.status') }}</label>
                    <select name="status" class="form-select">
                        <option value="">{{ __('expenses.all') }}</option>
                        @foreach([\App\Models\Expense::STATUS_DRAFT, \App\Models\Expense::STATUS_APPROVED, \App\Models\Expense::STATUS_PAID, \App\Models\Expense::STATUS_VOID] as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('expenses.status_'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.category') }}</label>
                    <select name="expense_category_id" class="form-select">
                        <option value="">{{ __('expenses.all') }}</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) $filters['expense_category_id'] === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.payee') }}</label>
                    <select name="payee_id" class="form-select">
                        <option value="">{{ __('expenses.all') }}</option>
                        @foreach($payees as $payee)
                            <option value="{{ $payee->id }}" @selected((int) $filters['payee_id'] === $payee->id)>{{ $payee->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.cash_account') }}</label>
                    <select name="cash_account_id" class="form-select">
                        <option value="">{{ __('expenses.all') }}</option>
                        @foreach($cashAccounts as $account)
                            <option value="{{ $account->id }}" @selected((int) $filters['cash_account_id'] === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.payment_method') }}</label>
                    <select name="payment_method" class="form-select">
                        <option value="">{{ __('expenses.all') }}</option>
                        @foreach($methodLabels as $value => $label)
                            <option value="{{ $value }}" @selected($filters['payment_method'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.search') }}</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('expenses.search_placeholder') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.date_from') }}</label>
                    <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('expenses.date_until') }}</label>
                    <input type="date" name="date_until" value="{{ old('date_until', request('date_until')) }}" class="form-control">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">{{ __('expenses.apply') }}</button>
                    <a href="{{ route('dashboard.finance.expenses.index') }}" class="btn btn-secondary">{{ __('expenses.reset') }}</a>
                </div>
            </form>
        </div>
    </div>

    {{-- ================= Table ================= --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('expenses.reference_number') }}</th>
                            <th>{{ __('expenses.title') }}</th>
                            <th>{{ __('expenses.category') }}</th>
                            <th>{{ __('expenses.payee') }}</th>
                            <th class="text-end">{{ __('expenses.amount') }}</th>
                            <th>{{ __('expenses.expense_date') }}</th>
                            <th>{{ __('expenses.status') }}</th>
                            <th>{{ __('expenses.cash_account') }}</th>
                            <th>{{ __('expenses.payment_method') }}</th>
                            <th>{{ __('expenses.created_by') }}</th>
                            <th>{{ __('expenses.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $expense)
                            <tr>
                                <td>{{ $expense->reference_number }}</td>
                                <td>{{ $expense->title }}</td>
                                <td>{{ $expense->expenseCategory?->name ?? '—' }}</td>
                                <td>{{ $expense->payee?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $expense->amount, 2, '.', ' ') }} {{ $expense->currency }}</td>
                                <td>{{ optional($expense->expense_date)->format('d.m.Y') }}</td>
                                <td>@include('dashboard.finance.expenses._status_badge', ['status' => $expense->status])</td>
                                <td>{{ $expense->cashAccount?->name ?? '—' }}</td>
                                <td>{{ $methodLabels[$expense->payment_method] ?? '—' }}</td>
                                <td>{{ $expense->creator?->name ?? '—' }}</td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="{{ route('dashboard.finance.expenses.show', $expense) }}" class="btn btn-sm btn-outline-secondary">{{ __('expenses.view') }}</a>
                                        @can('update', $expense)
                                            <a href="{{ route('dashboard.finance.expenses.edit', $expense) }}" class="btn btn-sm btn-outline-primary">{{ __('expenses.edit') }}</a>
                                        @endcan
                                        @can('approve', $expense)
                                            <form method="POST" action="{{ route('dashboard.finance.expenses.approve', $expense) }}" onsubmit="return confirm('{{ __('expenses.action_approve') }}?')">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-warning">{{ __('expenses.action_approve') }}</button>
                                            </form>
                                        @endcan
                                        @can('pay', $expense)
                                            <form method="POST" action="{{ route('dashboard.finance.expenses.pay', $expense) }}" onsubmit="return confirm('{{ __('expenses.action_pay') }}?')">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success">{{ __('expenses.action_pay') }}</button>
                                            </form>
                                        @endcan
                                        @can('void', $expense)
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#void-{{ $expense->id }}">{{ __('expenses.action_void') }}</button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @can('void', $expense)
                                <tr class="collapse" id="void-{{ $expense->id }}">
                                    <td colspan="11">
                                        <form method="POST" action="{{ route('dashboard.finance.expenses.void', $expense) }}" class="d-flex gap-2 align-items-start">
                                            @csrf
                                            <input type="text" name="void_reason" class="form-control form-control-sm" placeholder="{{ __('expenses.void_reason') }}" required>
                                            <button type="submit" class="btn btn-sm btn-danger text-nowrap" onclick="return confirm('{{ __('expenses.action_void') }}?')">{{ __('expenses.action_void') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endcan
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">{{ __('expenses.no_data') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $expenses->links() }}
    </div>
</div>
@endsection
