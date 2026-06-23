@extends('layouts.dashboard')

@section('content')

@php
    $totalIn = $transactions->where('type','in')->sum('amount');
    $totalOut = $transactions->where('type','out')->sum('amount');
@endphp

<div class="container py-4">


    <h3 class="mb-4">📊 {{ __('app.cash_reports') }}</h3>

    {{-- ================= Filters ================= --}}
    <div class="card mb-4">
        <div class="card-body">

            <form method="GET" class="row g-3">

                <div class="col-md-3">
                    <label class="form-label">{{ __('app.date') }} From</label>
                    <input type="date" name="from_date" value="{{ request('from_date') }}" class="form-control">
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('app.date') }} To</label>
                    <input type="date" name="to_date" value="{{ request('to_date') }}" class="form-control">
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ __('app.type') }}</label>
                    <select name="type" class="form-control">

                        <option value="">All</option>

                        <option value="in" {{ request('type')=='in'?'selected':'' }}>
                            {{ __('app.income') }}
                        </option>

                        <option value="out" {{ request('type')=='out'?'selected':'' }}>
                            {{ __('app.expenses') }}
                        </option>

                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end">

                    <button class="btn btn-primary me-2">
                        {{ __('app.search') }}
                    </button>

                    <a href="{{ route('dashboard.cash.reports') }}" class="btn btn-secondary">
                        {{ __('app.reset') }}
                    </a>

                </div>

            </form>

        </div>
    </div>

    {{-- ================= Summary ================= --}}
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="p-4 text-white rounded" style="background:#198754;">
                <div>{{ __('app.total_income') }}</div>
                <div class="fs-3 fw-bold">
                    {{ number_format($totalIn, 2) }}
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="p-4 text-white rounded" style="background:#dc3545;">
                <div>{{ __('app.total_expenses') }}</div>
                <div class="fs-3 fw-bold">
                    {{ number_format($totalOut, 2) }}
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="p-4 text-dark rounded" style="background:#ffc107;">
                <div>{{ __('app.net_balance') }}</div>
                <div class="fs-3 fw-bold">
                    {{ number_format($totalIn - $totalOut, 2) }}
                </div>
            </div>
        </div>

    </div>

    {{-- ================= Export Buttons ================= --}}
    <div class="mb-4">

        <a href="#" class="btn btn-success disabled">
            ⬇ {{ __('app.export_excel') }}
        </a>

        <a href="#" class="btn btn-danger disabled">
            ⬇ {{ __('app.export_pdf') }}
        </a>

    </div>

    {{-- ================= Chart ================= --}}
    <div class="card mb-4">

        <div class="card-header">
            📈 {{ __('app.cash_flow_chart') }}
        </div>

        <div class="card-body">
            <canvas id="cashChart"></canvas>
        </div>

    </div>

    {{-- ================= Transactions ================= --}}
    <div class="card">

        <div class="card-header">
            🧾 {{ __('app.reports') }}
        </div>

        <div class="card-body">

            <table class="table table-bordered table-striped">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>{{ __('app.date') }}</th>
                        <th>{{ __('app.accounts') }}</th>
                        <th>{{ __('app.type') }}</th>
                        <th>{{ __('app.amount') }}</th>
                        <th>{{ __('app.notes') }}</th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($transactions as $t)

                        <tr>

                            <td>{{ $t->id }}</td>

                            <td>{{ $t->created_at->format('Y-m-d') }}</td>

                            <td>{{ $t->account->name ?? '-' }}</td>

                            <td>
                                @if($t->type == 'in')
                                    <span class="badge bg-success">{{ __('app.income') }}</span>
                                @else
                                    <span class="badge bg-danger">{{ __('app.expenses') }}</span>
                                @endif
                            </td>

                            <td>{{ number_format($t->amount, 2) }}</td>

                            <td>{{ $t->notes ?? '-' }}</td>

                        </tr>

                    @empty

                        <tr>
                            <td colspan="6" class="text-center">
                                {{ __('app.no_records_found') }}
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

            <div class="mt-3">
                {{ $transactions->links() }}
            </div>

        </div>

    </div>

</div>

{{-- ================= Chart Script ================= --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const chartDates = @json($chartDates ?? []);
    const chartIn = @json($chartIn ?? []);
    const chartOut = @json($chartOut ?? []);

    new Chart(document.getElementById('cashChart'), {
        type: 'bar',
        data: {
            labels: chartDates,
            datasets: [
                {
                    label: @json(__('app.income')),
                    data: chartIn,
                    backgroundColor: '#198754'
                },
                {
                    label: @json(__('app.expenses')),
                    data: chartOut,
                    backgroundColor: '#dc3545'
                }
            ]
        }
    });
</script>

@endsection