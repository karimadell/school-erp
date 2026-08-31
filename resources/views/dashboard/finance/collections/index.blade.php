@extends('layouts.dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Поступления</h1>
            <p class="text-muted mb-0">Подтверждённые платежи учеников — только фактически полученные деньги</p>
        </div>
    </div>

    {{-- ================= Filters ================= --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Дата с</label>
                    <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Дата по</label>
                    <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Способ оплаты</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Все</option>
                        @foreach($methodLabels as $value => $label)
                            <option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Касса / счёт</label>
                    <select name="cash_account_id" class="form-select">
                        <option value="">Все</option>
                        @foreach($cashAccounts as $account)
                            <option value="{{ $account->id }}" @selected((int) request('cash_account_id') === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Услуга</label>
                    <select name="fee_id" class="form-select">
                        <option value="">Все</option>
                        @foreach($fees as $fee)
                            <option value="{{ $fee->id }}" @selected((int) request('fee_id') === $fee->id)>{{ $fee->name_ru }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ID ученика</label>
                    <input type="number" name="student_id" value="{{ old('student_id', request('student_id')) }}" class="form-control" min="1">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Применить</button>
                    <a href="{{ route('dashboard.finance.collections.index') }}" class="btn btn-secondary">Сбросить</a>
                </div>
            </form>
        </div>
    </div>

    {{-- ================= Totals ================= --}}
    <div class="row g-3 mb-2">
        <div class="col-12">
            <h6 class="text-muted mb-2">Фактически полученные деньги (по всем отфильтрованным платежам)</h6>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Всего получено</div>
                <strong class="fs-5">{{ number_format((float) $totals['total_collected_cash'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Возвраты (наличные)</div>
                <strong class="fs-5">{{ number_format((float) $totals['total_cash_refunds'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-primary"><div class="card-body">
                <div class="small text-muted">Чистые поступления</div>
                <strong class="fs-5">{{ number_format((float) $totals['net_cash_collections'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
    </div>

    <div class="row g-3 mb-2">
        <div class="col-12">
            <h6 class="text-muted mb-2 mt-2">
                Распределено по услугам
                @if(($filters['fee_id'] ?? null))
                    <span class="badge bg-info text-dark">только выбранная услуга</span>
                @endif
            </h6>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Распределено по услугам</div>
                <strong class="fs-5">{{ number_format((float) $totals['attributed_collections'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Распределённые возвраты</div>
                <strong class="fs-5">{{ number_format((float) $totals['attributed_refunds'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-success"><div class="card-body">
                <div class="small text-muted">Чисто по услугам</div>
                <strong class="fs-5">{{ number_format((float) $totals['net_attributed_collections'], 2, '.', ' ') }} EGP</strong>
            </div></div>
        </div>
    </div>

    <div class="alert alert-warning d-flex flex-wrap gap-3 mb-4">
        <div>
            <strong>Не распределено (не подтверждено по услугам):</strong>
            {{ number_format((float) $totals['unallocated_collections'], 2, '.', ' ') }} EGP
        </div>
        <div>
            <strong>Не распределено возвратов:</strong>
            {{ number_format((float) $totals['unallocated_refunds'], 2, '.', ' ') }} EGP
        </div>
        <div class="w-100 small mb-0">
            «Чисто по услугам» не равно «Чистым поступлениям», если есть неподтверждённая по услугам сумма — это историческая особенность данных, а не ошибка.
            @if(($filters['fee_id'] ?? null))
                При выбранном фильтре услуги суммы «Не распределено» показывают деньги вне выбранной услуги (другие услуги или действительно неподтверждённые), а не только историческую неопределённость.
            @endif
        </div>
    </div>

    {{-- ================= Table ================= --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Квитанция</th>
                            <th>Ученик</th>
                            <th>Класс</th>
                            <th>Услуга</th>
                            <th class="text-end">Получено</th>
                            <th class="text-end">Возврат</th>
                            <th class="text-end">Итого</th>
                            <th>Способ оплаты</th>
                            <th>Счёт</th>
                            <th>Принял</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $row)
                            @php
                                $payment = $row['payment'];
                                $allocations = $payment->allocations;
                            @endphp
                            <tr>
                                <td>{{ optional($payment->paid_at)->format('d.m.Y H:i') }}</td>
                                <td>{{ $payment->payment_number }}</td>
                                <td>{{ $payment->invoice?->student?->full_name }}</td>
                                <td>{{ $payment->invoice?->student?->class?->name_ru }}</td>
                                <td>
                                    @if($allocations->count() === 1)
                                        {{ $allocations->first()->item->fee?->name_ru ?? $allocations->first()->item->description }}
                                    @elseif($allocations->count() > 1)
                                        <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#breakdown-{{ $payment->id }}">
                                            {{ $allocations->count() }} услуги — показать
                                        </button>
                                    @elseif($row['status'] === \App\Services\Finance\PaymentAllocationStatus::NeedsReview)
                                        <span class="text-danger">Требует проверки</span>
                                    @else
                                        <span class="text-muted">Не распределено</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format((float) $row['gross'], 2, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format((float) $row['refunded'], 2, '.', ' ') }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $row['net'], 2, '.', ' ') }}</td>
                                <td>{{ $methodLabels[$payment->payment_method] ?? $payment->payment_method }}</td>
                                <td>{{ $payment->cashAccount?->name }}</td>
                                <td>{{ $payment->creator?->name }}</td>
                                <td>@include('dashboard.finance.collections._status_badge', ['status' => $row['status']])</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('dashboard.payments.receipt', $payment) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Квитанция</a>
                                        @if($allocations->count() > 1 || $row['refund_rows']->isNotEmpty())
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#breakdown-{{ $payment->id }}">Детали</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if($allocations->isNotEmpty() || $row['refund_rows']->isNotEmpty())
                                @include('dashboard.finance.collections._breakdown', ['row' => $row])
                            @endif
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">Платежи не найдены.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $payments->links() }}
    </div>
</div>
@endsection
