@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-4">📊 Admin Dashboard</h3>

    {{-- ================= Filters ================= --}}
    <form method="GET" class="mb-4 row g-3 align-items-center">
        <div class="col-auto">
            <label class="col-form-label">From:</label>
        </div>
        <div class="col-auto">
            <input type="date" name="from" class="form-control" value="{{ $from }}">
        </div>

        <div class="col-auto">
            <label class="col-form-label">To:</label>
        </div>
        <div class="col-auto">
            <input type="date" name="to" class="form-control" value="{{ $to }}">
        </div>

        <div class="col-auto">
            <button class="btn btn-primary">Apply</button>
        </div>
    </form>

    {{-- ================= KPI Cards ================= --}}
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="p-4 text-white rounded" style="background:#2e7d32;">
                <div>Total Income</div>
                <div style="font-size:32px;font-weight:700;">
                    {{ number_format($totalIncome, 2) }}
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="p-4 text-white rounded" style="background:#1e5bd7;">
                <div>Invoices</div>
                <div style="font-size:32px;font-weight:700;">
                    {{ $invoicesCount }}
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="p-4 text-dark rounded" style="background:#56c7f2;">
                <div>Students</div>
                <div style="font-size:32px;font-weight:700;">
                    {{ $studentsCount }}
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="p-4 text-dark rounded" style="background:#f6c343;">
                <div>Cash Accounts</div>
                <div style="font-size:32px;font-weight:700;">
                    {{ $cashAccountsCount }}
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="p-4 text-white rounded" style="background:#6c757d;">
                <div>Cash Transactions</div>
                <div style="font-size:32px;font-weight:700;">
                    {{ $transactionsCount }}
                </div>
            </div>
        </div>

    </div>

    {{-- ================= Charts ================= --}}
    <div class="row g-4">

        {{-- Invoices Daily --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">🧾 Invoices (Daily)</div>
                <div class="card-body">
                    <canvas id="invoiceDailyChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Cash Daily --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">💰 Cash Flow (Daily)</div>
                <div class="card-body">
                    <canvas id="cashDailyChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Cash Monthly --}}
        <div class="col-md-12">
            <div class="card mt-4">
                <div class="card-header">📅 Cash Flow (Monthly)</div>
                <div class="card-body">
                    <canvas id="cashMonthlyChart"></canvas>
                </div>
            </div>
        </div>

    </div>

</div>

{{-- ================= Charts Scripts ================= --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/* Invoices Daily */
new Chart(document.getElementById('invoiceDailyChart'), {
    type: 'line',
    data: {
        labels: {!! json_encode($invoiceDaily->keys()) !!},
        datasets: [{
            label: 'Invoices',
            data: {!! json_encode($invoiceDaily->values()) !!},
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13,110,253,0.1)',
            fill: true,
            tension: 0.3
        }]
    }
});

/* Cash Daily */
new Chart(document.getElementById('cashDailyChart'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($cashDaily['in']->pluck('date')) !!},
        datasets: [
            {
                label: 'IN',
                data: {!! json_encode($cashDaily['in']->pluck('total')) !!},
                backgroundColor: '#198754'
            },
            {
                label: 'OUT',
                data: {!! json_encode($cashDaily['out']->pluck('total')) !!},
                backgroundColor: '#dc3545'
            }
        ]
    }
});

/* Cash Monthly */
new Chart(document.getElementById('cashMonthlyChart'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($cashMonthly['in']->pluck('month')) !!},
        datasets: [
            {
                label: 'IN',
                data: {!! json_encode($cashMonthly['in']->pluck('total')) !!},
                backgroundColor: '#0d6efd'
            },
            {
                label: 'OUT',
                data: {!! json_encode($cashMonthly['out']->pluck('total')) !!},
                backgroundColor: '#6c757d'
            }
        ]
    }
});
</script>
@endsection