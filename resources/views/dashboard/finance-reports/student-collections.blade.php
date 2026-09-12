@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Поступления от учеников</h1>
            <p class="text-muted mb-0">Сводка по подтверждённым платежам и возвратам учеников (InvoicePayment / PaymentRefund) — только просмотр. Не включает расходы и прочие доходы.</p>
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
                <div class="col-md-3">
                    <label class="form-label">Касса / счёт</label>
                    <select name="cash_account_id" class="form-select">
                        <option value="">Все</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) ($filters['cash_account_id'] ?? 0) === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Применить</button>
                    <a href="{{ route('dashboard.finance.reports.student-collections') }}" class="btn btn-secondary">Сбросить</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Валовые поступления</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['grossPayments'], 2) }}</div>
                <div class="text-muted small">{{ $summary['paymentsCount'] }} платежей</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Возвраты</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['grossRefunds'], 2) }}</div>
                <div class="text-muted small">{{ $summary['refundsCount'] }} возвратов</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Нетто</div>
                <div class="h4 mb-0">{{ number_format((float) $summary['net'], 2) }}</div>
            </div></div>
        </div>
    </div>

    <a href="{{ route('dashboard.finance.collections.index') }}" class="btn btn-outline-primary">Подробная детализация «Поступления» →</a>
</div>
@endsection
