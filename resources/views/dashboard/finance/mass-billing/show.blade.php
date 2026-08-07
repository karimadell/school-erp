@extends('layouts.dashboard')
@section('content')
<div class="container py-4">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @php($editable = in_array($batch->status, [\App\Models\BillingBatch::STATUS_DRAFT, \App\Models\BillingBatch::STATUS_PREVIEWED], true))

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2 class="mb-1">{{ __('mass_billing.show_title') }}</h2>
            <span class="badge bg-secondary">{{ __('mass_billing.status.'.$batch->status) }}</span>
        </div>
        <div class="d-flex gap-2">
            @if($editable)
                <a href="{{ route('dashboard.finance.mass-billing.edit', $batch) }}" class="btn btn-outline-secondary">{{ __('mass_billing.actions.edit') }}</a>
            @endif
            <a href="{{ route('dashboard.finance.mass-billing.index') }}" class="btn btn-outline-secondary">{{ __('mass_billing.actions.back') }}</a>
        </div>
    </div>

    <div class="card card-body mt-3">
        <dl class="row mb-0">
            <dt class="col-sm-3">{{ __('mass_billing.fields.academic_year') }}</dt><dd class="col-sm-9">{{ $batch->academicYear?->name }}</dd>
            <dt class="col-sm-3">{{ __('mass_billing.fields.service') }}</dt><dd class="col-sm-9">{{ $batch->fee?->name_ru }}</dd>
            <dt class="col-sm-3">{{ __('mass_billing.fields.quantity') }}</dt><dd class="col-sm-9">{{ $batch->quantity }}</dd>
            <dt class="col-sm-3">{{ __('mass_billing.fields.issue_date') }}</dt><dd class="col-sm-9">{{ $batch->issue_date?->toDateString() }}</dd>
            <dt class="col-sm-3">{{ __('mass_billing.fields.due_date') }}</dt><dd class="col-sm-9">{{ $batch->due_date?->toDateString() }}</dd>
            @if($batch->description)
                <dt class="col-sm-3">{{ __('mass_billing.fields.description') }}</dt><dd class="col-sm-9">{{ $batch->description }}</dd>
            @endif
        </dl>
    </div>

    @if($editable)
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <form method="POST" action="{{ route('dashboard.finance.mass-billing.preview', $batch) }}">
                @csrf
                <button class="btn btn-outline-primary">{{ __('mass_billing.actions.preview') }}</button>
            </form>
            @can('execute mass billing')
                @if($batch->status === \App\Models\BillingBatch::STATUS_PREVIEWED)
                    <form method="POST" action="{{ route('dashboard.finance.mass-billing.execute', $batch) }}"
                          onsubmit="return confirm('{{ __('mass_billing.execute_hint') }}');">
                        @csrf
                        <button class="btn btn-primary">{{ __('mass_billing.actions.execute') }}</button>
                    </form>
                    <span class="text-muted">{{ __('mass_billing.execute_hint') }}</span>
                @endif
            @endcan
        </div>
    @endif

    <h4 class="mt-4">{{ __('mass_billing.preview_title') }}</h4>
    <p class="text-muted small">{{ __('mass_billing.preview_recheck_note') }}</p>
    @if(is_null($batch->preview_snapshot))
        <p class="text-muted">{{ __('mass_billing.empty.preview') }}</p>
    @else
        <div class="row g-2 my-2">
            <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.summary.selected_count') }}</small><div class="fs-5">{{ $batch->selected_count }}</div></div></div>
            <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.summary.eligible_count') }}</small><div class="fs-5">{{ $batch->eligible_count }}</div></div></div>
            <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.summary.skipped_count') }}</small><div class="fs-5">{{ $batch->skipped_count }}</div></div></div>
            <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.summary.expected_invoice_count') }}</small><div class="fs-5">{{ $batch->expected_invoice_count }}</div></div></div>
            <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.summary.expected_total_amount') }}</small><div class="fs-5">{{ $batch->expected_total_amount }} {{ $batch->currency }}</div></div></div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>{{ __('mass_billing.table.student') }}</th>
                        <th>{{ __('mass_billing.table.class') }}</th>
                        <th>{{ __('mass_billing.table.service') }}</th>
                        <th class="text-end">{{ __('mass_billing.table.unit_price') }}</th>
                        <th class="text-end">{{ __('mass_billing.table.quantity') }}</th>
                        <th class="text-end">{{ __('mass_billing.table.total') }}</th>
                        <th>{{ __('mass_billing.table.eligibility') }}</th>
                        <th>{{ __('mass_billing.table.reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($batch->preview_snapshot as $row)
                        <tr>
                            <td>{{ $row['student_name'] }}</td>
                            <td>{{ $row['class_name'] ?? '—' }}</td>
                            <td>{{ $row['fee_name'] }}</td>
                            <td class="text-end">{{ $row['unit_price'] ?? '—' }}</td>
                            <td class="text-end">{{ $row['quantity'] }}</td>
                            <td class="text-end">{{ $row['total'] ?? '—' }}</td>
                            <td>
                                @if($row['eligible'])
                                    <span class="badge bg-success">{{ __('mass_billing.table.eligible') }}</span>
                                @else
                                    <span class="badge bg-warning text-dark">{{ __('mass_billing.table.skipped') }}</span>
                                @endif
                            </td>
                            <td>{{ $row['skip_reason'] ? __('mass_billing.skip_reasons.'.$row['skip_reason']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h4 class="mt-4">{{ __('mass_billing.run.title') }}</h4>
    @php($run = $batch->latestRun)
    @if(is_null($run))
        <p class="text-muted">{{ __('mass_billing.run.no_run') }}</p>
    @else
        <div class="mb-2">
            <span class="badge {{ $run->status === \App\Models\BillingRun::STATUS_FAILED ? 'bg-danger' : 'bg-success' }}">
                {{ __('mass_billing.run.status.'.$run->status) }}
            </span>
            <span class="text-muted ms-2">
                {{ __('mass_billing.run.executor') }}: {{ $run->executor?->name ?? '—' }} ·
                {{ __('mass_billing.run.executed_at') }}: {{ optional($run->finished_at)->format('Y-m-d H:i') ?? '—' }} ·
                {{ __('mass_billing.run.trigger') }}: {{ __('mass_billing.run.trigger_manual') }}
            </span>
        </div>

        @if($run->status === \App\Models\BillingRun::STATUS_FAILED)
            <div class="alert alert-danger">{{ __('mass_billing.run.failed_summary') }}</div>
        @else
            <div class="row g-2 my-2">
                <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.run.generated_count') }}</small><div class="fs-5">{{ $run->created_count }}</div></div></div>
                <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.run.skipped_count') }}</small><div class="fs-5">{{ $run->skipped_count }}</div></div></div>
                <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.run.failed_count') }}</small><div class="fs-5">{{ $run->failed_count }}</div></div></div>
                <div class="col"><div class="card card-body py-2"><small class="text-muted">{{ __('mass_billing.run.total_generated') }}</small><div class="fs-5">{{ $run->total_amount }} {{ $batch->currency }}</div></div></div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('mass_billing.table.student') }}</th>
                            <th>{{ __('mass_billing.table.class') }}</th>
                            <th class="text-end">{{ __('mass_billing.table.total') }}</th>
                            <th>{{ __('mass_billing.table.eligibility') }}</th>
                            <th>{{ __('mass_billing.table.reason') }}</th>
                            <th>{{ __('mass_billing.run.invoice') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($run->items as $item)
                            <tr>
                                <td>{{ $item->student?->full_name ?? data_get($item->context, 'student_name') ?? '—' }}</td>
                                <td>{{ data_get($item->context, 'class_name') ?? '—' }}</td>
                                <td class="text-end">{{ $item->amount ?? '—' }}</td>
                                <td>
                                    @if($item->status === \App\Models\BillingRunItem::STATUS_GENERATED)
                                        <span class="badge bg-success">{{ __('mass_billing.item_status.generated') }}</span>
                                    @else
                                        <span class="badge bg-warning text-dark">{{ __('mass_billing.item_status.'.$item->status) }}</span>
                                    @endif
                                </td>
                                <td>{{ $item->skip_reason ? __('mass_billing.skip_reasons.'.$item->skip_reason) : '—' }}</td>
                                <td>
                                    @if($item->invoice_id)
                                        <a href="{{ route('dashboard.invoices.show', $item->invoice_id) }}"
                                           title="{{ __('mass_billing.run.view_invoice') }}"
                                           aria-label="{{ __('mass_billing.run.view_invoice') }}">{{ $item->invoice?->invoice_number ?? ('#'.$item->invoice_id) }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
@endsection
