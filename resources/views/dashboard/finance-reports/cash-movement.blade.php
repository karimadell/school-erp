@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Движение денежных средств</h1>
            <p class="text-muted mb-0">Только просмотр — данные из кассовых операций (CashTransaction), сгруппированные по кассе, способу оплаты, типу источника и дню.</p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Дата с</label>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Дата по</label>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Касса / счёт</label>
                    <select name="cash_account_id" class="form-select">
                        <option value="">Все</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) ($filters['cash_account_id'] ?? 0) === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Тип</label>
                    <select name="type" class="form-select">
                        <option value="">Все</option>
                        <option value="in" @selected(($filters['type'] ?? '') === 'in')>Приход</option>
                        <option value="out" @selected(($filters['type'] ?? '') === 'out')>Расход</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Способ оплаты</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Все</option>
                        @foreach($paymentMethods as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['payment_method'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Тип источника</label>
                    <select name="source_type" class="form-select">
                        <option value="">Все</option>
                        @foreach($sourceTypes as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['source_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Применить</button>
                    <a href="{{ route('dashboard.finance.reports.cash-movement') }}" class="btn btn-secondary">Сбросить</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Приход</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['totalIn'], 2) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Расход</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['totalOut'], 2) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Нетто</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['net'], 2) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Остаток на начало / конец периода</div>
                <div class="h6 mb-0">
                    {{ $summary['openingBalance'] !== null ? number_format((float) $summary['openingBalance'], 2) : '—' }}
                    /
                    {{ $summary['closingBalance'] !== null ? number_format((float) $summary['closingBalance'], 2) : '—' }}
                </div>
            </div></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">По кассе</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Касса</th><th class="text-end">Приход</th><th class="text-end">Расход</th></tr></thead>
                        <tbody>
                        @forelse($summary['byAccount'] as $row)
                            <tr><td>{{ $row->cash_account_name }}</td><td class="text-end">{{ number_format((float) $row->total_in, 2) }}</td><td class="text-end">{{ number_format((float) $row->total_out, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Нет данных.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">По способу оплаты</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Способ</th><th class="text-end">Приход</th><th class="text-end">Расход</th></tr></thead>
                        <tbody>
                        @forelse($summary['byMethod'] as $row)
                            <tr><td>{{ $paymentMethods[$row->payment_method] ?? ($row->payment_method ?? '—') }}</td><td class="text-end">{{ number_format((float) $row->total_in, 2) }}</td><td class="text-end">{{ number_format((float) $row->total_out, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Нет данных.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">По типу источника</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Источник</th><th class="text-end">Приход</th><th class="text-end">Расход</th></tr></thead>
                        <tbody>
                        @forelse($summary['bySourceType'] as $row)
                            <tr><td>{{ \App\Services\Finance\Reporting\FinanceSourceType::label($row->source_type) }}</td><td class="text-end">{{ number_format((float) $row->total_in, 2) }}</td><td class="text-end">{{ number_format((float) $row->total_out, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Нет данных.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Операции</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Дата</th><th>Касса</th><th>Тип</th><th>Источник</th><th>Способ</th><th class="text-end">Сумма</th><th>Кто провёл</th><th>Описание</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $transaction->account?->name }}</td>
                            <td>{{ $transaction->type === 'in' ? 'Приход' : 'Расход' }}</td>
                            <td>{{ \App\Services\Finance\Reporting\FinanceSourceType::label($transaction->reporting_source_type) }}</td>
                            <td>{{ $paymentMethods[$transaction->payment_method] ?? ($transaction->payment_method ?? '—') }}</td>
                            <td class="text-end">{{ number_format((float) $transaction->amount, 2) }}</td>
                            <td>{{ $transaction->creator?->name ?? '—' }}</td>
                            <td>{{ $transaction->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Нет операций за выбранный период.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">
            {{ $transactions->links() }}
        </div>
    </div>
</div>
@endsection
