@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h3 class="mb-1">{{ $expense->title }}</h3>
            <div class="text-muted">{{ $expense->reference_number }}</div>
        </div>
        <div class="d-flex gap-2">
            @can('update', $expense)
                <a href="{{ route('dashboard.finance.expenses.edit', $expense) }}" class="btn btn-outline-primary">{{ __('expenses.edit') }}</a>
            @endcan
            <a href="{{ route('dashboard.finance.expenses.index') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light fw-bold">{{ __('expenses.section_details') }}</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('expenses.status') }}</dt>
                        <dd class="col-sm-8">@include('dashboard.finance.expenses._status_badge', ['status' => $expense->status])</dd>

                        <dt class="col-sm-4">{{ __('expenses.amount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $expense->amount, 2, '.', ' ') }} {{ $expense->currency }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.expense_date') }}</dt>
                        <dd class="col-sm-8">{{ optional($expense->expense_date)->format('d.m.Y') }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.category') }}</dt>
                        <dd class="col-sm-8">{{ $expense->expenseCategory?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.payee') }}</dt>
                        <dd class="col-sm-8">{{ $expense->payee?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.cash_account') }}</dt>
                        <dd class="col-sm-8">{{ $expense->cashAccount?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.payment_method') }}</dt>
                        <dd class="col-sm-8">{{ $methodLabels[$expense->payment_method] ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.external_reference') }}</dt>
                        <dd class="col-sm-8">{{ $expense->external_reference ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.description') }}</dt>
                        <dd class="col-sm-8">{{ $expense->description ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.notes') }}</dt>
                        <dd class="col-sm-8">{{ $expense->notes ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('expenses.attachment') }}</dt>
                        <dd class="col-sm-8">
                            @if($expense->attachment_path)
                                <a href="{{ route('dashboard.finance.expenses.attachment', $expense) }}">{{ $expense->attachment_name ?? __('expenses.attachment_download') }}</a>
                            @else
                                {{ __('expenses.attachment_none') }}
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-light fw-bold">{{ __('expenses.ledger_transaction') }}</div>
                <div class="card-body">
                    @if($expense->cashTransaction)
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ID</dt>
                            <dd class="col-sm-8">#{{ $expense->cashTransaction->id }}</dd>
                            <dt class="col-sm-4">{{ __('expenses.amount') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $expense->cashTransaction->amount, 2, '.', ' ') }}</dd>
                            <dt class="col-sm-4">{{ __('expenses.expense_date') }}</dt>
                            <dd class="col-sm-8">{{ optional($expense->cashTransaction->created_at)->format('d.m.Y H:i') }}</dd>
                        </dl>
                    @else
                        <p class="text-muted mb-0">{{ __('expenses.ledger_none') }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light fw-bold">{{ __('expenses.actions') }}</div>
                <div class="card-body d-flex flex-column gap-2">
                    @can('approve', $expense)
                        <form method="POST" action="{{ route('dashboard.finance.expenses.approve', $expense) }}" onsubmit="return confirm('{{ __('expenses.action_approve') }}?')">
                            @csrf
                            <button type="submit" class="btn btn-warning w-100">{{ __('expenses.action_approve') }}</button>
                        </form>
                    @endcan
                    @can('pay', $expense)
                        <form method="POST" action="{{ route('dashboard.finance.expenses.pay', $expense) }}" onsubmit="return confirm('{{ __('expenses.action_pay') }}?')">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">{{ __('expenses.action_pay') }}</button>
                        </form>
                    @endcan
                    @can('void', $expense)
                        <form method="POST" action="{{ route('dashboard.finance.expenses.void', $expense) }}" onsubmit="return confirm('{{ __('expenses.action_void') }}?')">
                            @csrf
                            <label class="form-label small">{{ __('expenses.void_reason') }}</label>
                            <textarea name="void_reason" class="form-control form-control-sm mb-2" rows="2" required></textarea>
                            <button type="submit" class="btn btn-danger w-100">{{ __('expenses.action_void') }}</button>
                        </form>
                    @endcan
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-light fw-bold">{{ __('expenses.section_details') }}</div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-6">{{ __('expenses.created_by') }}</dt>
                        <dd class="col-6">{{ $expense->creator?->name ?? '—' }}</dd>

                        <dt class="col-6">{{ __('expenses.action_approve') }}</dt>
                        <dd class="col-6">{{ $expense->approver?->name ?? '—' }} @if($expense->approved_at) <br>{{ $expense->approved_at->format('d.m.Y H:i') }} @endif</dd>

                        <dt class="col-6">{{ __('expenses.action_pay') }}</dt>
                        <dd class="col-6">{{ $expense->payer?->name ?? '—' }} @if($expense->paid_at) <br>{{ $expense->paid_at->format('d.m.Y H:i') }} @endif</dd>

                        <dt class="col-6">{{ __('expenses.action_void') }}</dt>
                        <dd class="col-6">
                            {{ $expense->voider?->name ?? '—' }}
                            @if($expense->voided_at) <br>{{ $expense->voided_at->format('d.m.Y H:i') }} @endif
                            @if($expense->void_reason) <br><span class="text-muted">{{ $expense->void_reason }}</span> @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
