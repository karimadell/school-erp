@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                🧾 {{ __('invoices.title') }}
            </h3>
            <small class="text-muted">
                {{ __('invoices.subtitle') ?? 'Invoices management' }}
            </small>
        </div>

        <a href="{{ route('dashboard.invoices.create') }}" class="btn btn-primary">
            + {{ __('invoices.create') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">#</th>
                            <th>{{ __('invoices.student') }}</th>
                            <th>{{ __('invoices.services') }}</th>
                            <th style="width:140px;">{{ __('invoices.total_amount') }}</th>
                            <th style="width:160px;">{{ __('invoices.cash_account') }}</th>
                            <th style="width:140px;">{{ __('invoices.status') }}</th>
                            <th style="width:140px;">{{ __('invoices.date') }}</th>
                            <th class="text-end" style="width:220px;">{{ __('invoices.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($invoices as $invoice)
                            <tr>
                                <td class="fw-bold">
                                    #{{ $invoice->id }}
                                </td>

                                <td>
                                    {{ $invoice->student?->name ?? $invoice->customer_name ?? '—' }}
                                </td>

                                <td>
                                    @forelse($invoice->fees as $fee)
                                        <span class="badge bg-primary-subtle text-dark mb-1">
                                            {{ $fee->name_ru ?? $fee->name ?? '—' }}
                                            —
                                            {{ number_format($fee->pivot->amount ?? 0, 2) }}
                                        </span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>

                                <td>
                                    <strong>
                                        {{ number_format($invoice->total_amount, 2) }}
                                    </strong>
                                </td>

                                <td>
                                    {{ $invoice->cashAccount?->name ?? '—' }}
                                </td>

                                <td>
                                    @if($invoice->status === 'paid')
                                        <span class="badge bg-success">
                                            {{ __('invoices.paid') }}
                                        </span>
                                    @elseif($invoice->status === 'unpaid' || $invoice->status === 'pending')
                                        <span class="badge bg-warning text-dark">
                                            {{ __('invoices.unpaid') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">
                                            {{ __('invoices.cancelled') }}
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    {{ $invoice->created_at?->format('Y-m-d') ?? '—' }}
                                </td>

                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="{{ route('dashboard.invoices.show', $invoice) }}"
                                           class="btn btn-sm btn-outline-secondary">
                                            👁
                                        </a>

                                        <a href="{{ route('dashboard.invoices.print', $invoice) }}"
                                           class="btn btn-sm btn-outline-dark"
                                           target="_blank">
                                            🖨
                                        </a>

                                        <a href="{{ route('dashboard.invoices.pdf', $invoice) }}"
                                           class="btn btn-sm btn-outline-danger">
                                            📄
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    {{ __('invoices.no_data') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>

</div>

@endsection