@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h3 class="mb-1">{{ $entry->category?->name_ru }}</h3>
            <div class="text-muted">{{ $entry->reference_number }}</div>
        </div>
        <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('revenues.back') }}</a>
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
                <div class="card-header bg-light fw-bold">{{ __('revenues.section_details') }}</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('revenues.status') }}</dt>
                        <dd class="col-sm-8">@include('dashboard.finance.income.revenue._status_badge', ['status' => $entry->status])</dd>

                        <dt class="col-sm-4">{{ __('revenues.amount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $entry->amount, 2, '.', ' ') }} EGP</dd>

                        <dt class="col-sm-4">{{ __('revenues.revenue_date') }}</dt>
                        <dd class="col-sm-8">{{ optional($entry->revenue_date)->format('d.m.Y') }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.category') }}</dt>
                        <dd class="col-sm-8">{{ $entry->category?->name_ru ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.cash_account') }}</dt>
                        <dd class="col-sm-8">{{ $entry->cashAccount?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.payment_method') }}</dt>
                        <dd class="col-sm-8">{{ $entry->payment_method ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.payer_name') }}</dt>
                        <dd class="col-sm-8">{{ $entry->payer_name ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.description') }}</dt>
                        <dd class="col-sm-8">{{ $entry->description ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.notes') }}</dt>
                        <dd class="col-sm-8">{{ $entry->notes ?? '—' }}</dd>

                        <dt class="col-sm-4">{{ __('revenues.attachment') }}</dt>
                        <dd class="col-sm-8">
                            @if($entry->attachment_path)
                                <a href="{{ route('dashboard.finance.income.revenue.attachment', $entry) }}">{{ $entry->attachment_name ?? __('revenues.attachment_download') }}</a>
                            @else
                                {{ __('revenues.attachment_none') }}
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-light fw-bold">{{ __('revenues.ledger_transaction') }}</div>
                <div class="card-body">
                    @if($entry->cashTransaction)
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ID</dt>
                            <dd class="col-sm-8">#{{ $entry->cashTransaction->id }}</dd>
                            <dt class="col-sm-4">{{ __('revenues.amount') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $entry->cashTransaction->amount, 2, '.', ' ') }}</dd>
                        </dl>
                    @else
                        <p class="text-muted mb-0">{{ __('revenues.ledger_none') }}</p>
                    @endif
                    @if($entry->reversalTransaction)
                        <hr>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('revenues.action_reverse') }} ID</dt>
                            <dd class="col-sm-8">#{{ $entry->reversalTransaction->id }}</dd>
                            <dt class="col-sm-4">{{ __('revenues.reversal_reason') }}</dt>
                            <dd class="col-sm-8">{{ $entry->reversal_reason }}</dd>
                        </dl>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light fw-bold">{{ __('revenues.actions') }}</div>
                <div class="card-body d-flex flex-column gap-2">
                    @can('post', $entry)
                        <form method="POST" action="{{ route('dashboard.finance.income.revenue.post', $entry) }}" onsubmit="return confirm('{{ __('revenues.action_post') }}?')">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">{{ __('revenues.action_post') }}</button>
                        </form>
                    @endcan
                    @can('reverse', $entry)
                        <form method="POST" action="{{ route('dashboard.finance.income.revenue.reverse', $entry) }}" onsubmit="return confirm('{{ __('revenues.action_reverse') }}?')">
                            @csrf
                            <label class="form-label small">{{ __('revenues.reversal_reason') }}</label>
                            <textarea name="reversal_reason" class="form-control form-control-sm mb-2" rows="2" required></textarea>
                            <button type="submit" class="btn btn-danger w-100">{{ __('revenues.action_reverse') }}</button>
                        </form>
                    @endcan
                    @can('delete', $entry)
                        <form method="POST" action="{{ route('dashboard.finance.income.revenue.destroy', $entry) }}" onsubmit="return confirm('{{ __('revenues.delete_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('revenues.delete') }}</button>
                        </form>
                    @endcan
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-light fw-bold">{{ __('revenues.section_details') }}</div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-6">{{ __('revenues.created_by') }}</dt>
                        <dd class="col-6">{{ $entry->creator?->name ?? '—' }}</dd>

                        <dt class="col-6">{{ __('revenues.action_post') }}</dt>
                        <dd class="col-6">{{ $entry->poster?->name ?? '—' }} @if($entry->posted_at) <br>{{ $entry->posted_at->format('d.m.Y H:i') }} @endif</dd>

                        <dt class="col-6">{{ __('revenues.action_reverse') }}</dt>
                        <dd class="col-6">
                            {{ $entry->reverser?->name ?? '—' }}
                            @if($entry->reversed_at) <br>{{ $entry->reversed_at->format('d.m.Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
