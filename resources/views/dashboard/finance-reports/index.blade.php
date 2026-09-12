@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Финансовая сводка</h1>
            <p class="text-muted mb-0">Читаемая сводка по денежным движениям и поступлениям от учеников. Только просмотр — без изменения финансовых данных.</p>
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
                    <a href="{{ route('dashboard.finance.reports.index') }}" class="btn btn-secondary">Текущий месяц</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @if($canViewCash)
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Приход (касса)</div>
                <div class="h4 mb-0">{{ number_format((float) $cash['totalIn'], 2) }} EGP</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Расход (касса)</div>
                <div class="h4 mb-0">{{ number_format((float) $cash['totalOut'], 2) }} EGP</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Чистое движение</div>
                <div class="h4 mb-0">{{ number_format((float) $cash['net'], 2) }} EGP</div>
            </div></div>
        </div>
        @endif
        @if($canViewCollections)
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Поступления от учеников (нетто)</div>
                <div class="h4 mb-0">{{ number_format((float) $collections['net'], 2) }} EGP</div>
                <div class="text-muted small">{{ $collections['paymentsCount'] }} платежей, {{ $collections['refundsCount'] }} возвратов</div>
            </div></div>
        </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        @if($canViewCash)
        <div class="col-md-6">
            <a href="{{ route('dashboard.finance.reports.cash-movement') }}" class="btn btn-outline-primary w-100">Движение денежных средств →</a>
        </div>
        @endif
        @if($canViewCollections)
        <div class="col-md-6">
            <a href="{{ route('dashboard.finance.reports.student-collections') }}" class="btn btn-outline-primary w-100">Поступления от учеников →</a>
        </div>
        @endif
        @if($canViewCash)
        <div class="col-md-6">
            <a href="{{ route('dashboard.finance.reports.account-balances') }}" class="btn btn-outline-secondary w-100">Балансы счетов →</a>
        </div>
        @endif
    </div>

    @if($canViewCash && !($nonStudentAvailable['expenses'] && $nonStudentAvailable['revenues']))
        <div class="alert alert-secondary">
            Детализация по расходам и прочим доходам (не связанным с оплатой учеников) станет доступна после интеграции соответствующих модулей. Общие суммы по категориям «Расход» / «Прочий доход» уже включены в «Движение денежных средств» выше.
        </div>
    @endif

    <div class="card">
        <div class="card-header">Ежедневная сводка</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Дата</th>
                        @if($canViewCash)
                        <th class="text-end">Приход</th>
                        <th class="text-end">Расход</th>
                        <th class="text-end">Нетто</th>
                        @endif
                        @if($canViewCollections)
                        <th class="text-end">Поступления от учеников</th>
                        <th class="text-end">Возвраты</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($daily as $row)
                        <tr>
                            <td>{{ $row['day'] }}</td>
                            @if($canViewCash)
                            <td class="text-end">{{ number_format((float) $row['total_in'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['total_out'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['net'], 2) }}</td>
                            @endif
                            @if($canViewCollections)
                            <td class="text-end">{{ number_format((float) $row['student_collections'], 2) }} ({{ $row['student_collections_count'] }})</td>
                            <td class="text-end">{{ number_format((float) $row['student_refunds'], 2) }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ 1 + ($canViewCash ? 3 : 0) + ($canViewCollections ? 2 : 0) }}" class="text-center text-muted py-4">Нет данных за выбранный период.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
