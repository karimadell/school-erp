@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Балансы счетов</h1>
            <p class="text-muted mb-0">Текущий остаток по каждой кассе/счёту и движение за выбранный период. Только просмотр — остаток счёта не пересчитывается здесь.</p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Дата с</label>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Дата по</label>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Применить</button>
                    <a href="{{ route('dashboard.finance.reports.account-balances') }}" class="btn btn-secondary">Текущий месяц</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Касса / счёт</th>
                        <th class="text-end">Текущий остаток</th>
                        <th class="text-end">Приход за период</th>
                        <th class="text-end">Расход за период</th>
                        <th class="text-end">Нетто за период</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($balances as $row)
                        <tr>
                            <td>{{ $row['account']->name }}</td>
                            <td class="text-end">{{ number_format((float) $row['current_balance'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['total_in'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['total_out'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['net'], 2) }}</td>
                            <td><a href="{{ route('dashboard.finance.reports.cash-movement', ['cash_account_id' => $row['account']->id]) }}" class="btn btn-sm btn-outline-secondary">Операции →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Нет счетов.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
