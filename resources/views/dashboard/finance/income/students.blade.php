@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">{{ __('finance_workspace.income_students_title') }}</h1>
            <p class="text-muted mb-0">{{ __('finance_workspace.income_students_hint') }}</p>
        </div>
        <div class="d-flex gap-2">
            @can('manage invoices')
                <a class="btn btn-primary" href="{{ route('dashboard.invoices.create') }}">{{ __('finance_uat.issue_invoice') }}</a>
            @endcan
            <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
        </div>
    </div>

    <div class="row g-3 mb-4">@foreach(['Начислено'=>'invoiced','Оплачено'=>'paid','Остаток'=>'remaining','Просрочено'=>'overdue','Платежи сегодня'=>'today'] as $label=>$key)<div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">{{ $label }}</div><strong>{{ number_format((float)$totals[$key],2,'.',' ') }} EGP</strong></div></div></div>@endforeach</div>
    <form class="card border-0 shadow-sm mb-4"><div class="card-body row g-3"><div class="col-lg-4"><label class="form-label">Ученик</label><input class="form-control" name="q" value="{{ request('q') }}" placeholder="ФИО, телефон или ID"></div><div class="col-md-3"><label class="form-label">Учебный год</label><select class="form-select" name="academic_year_id"><option value="">Все</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(request('academic_year_id')==$year->id)>{{ $year->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Статус счёта</label><select class="form-select" name="status"><option value="">Все</option><option value="unpaid" @selected(request('status')==='unpaid')>Не оплачен</option><option value="partial" @selected(request('status')==='partial')>Частично оплачен</option><option value="paid" @selected(request('status')==='paid')>Оплачен</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Найти</button></div><div class="col-md-3"><label class="form-label">Счета с даты</label><input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}"></div><div class="col-md-3"><label class="form-label">Счета по дату</label><input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}"></div><div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="overdue" value="1" @checked(request('overdue'))><label class="form-check-label">Только просроченные</label></div></div></div></form>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Ученик</th><th>Учебный год</th><th>Начислено</th><th>Оплачено</th><th>Остаток</th><th>Просрочено</th><th>Последний счёт</th><th>Последний платёж</th><th>Действия</th></tr></thead><tbody>
    @forelse($students as $row)
        @php
            $student = $row['student'];
            $summary = $row['summary'];
            $payable = $summary['invoices']->whereIn('status', ['unpaid', 'partial']);
        @endphp
<tr><td>{{ $student->full_name }}<div class="small text-muted">ID {{ $student->id }} · {{ $student->phone }}</div></td><td>{{ $student->currentEnrollment?->academicYear?->name ?: '—' }}</td><td>{{ $summary['invoiced'] }} EGP</td><td>{{ $summary['paid'] }} EGP</td><td>{{ $summary['remaining'] }} EGP</td><td>{{ $summary['overdue'] }} EGP</td><td>{{ $summary['invoices']->first()?->display_number ?: '—' }}</td><td>{{ $summary['latest_payment']?->payment_number ?: '—' }}</td><td>
        <div class="d-flex flex-wrap gap-1 mb-1">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('dashboard.students.show',$student) }}">Открыть профиль</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('dashboard.students.finance',$student) }}">{{ __('finance_uat.student_account') }}</a>
            @can('manage invoices')<a class="btn btn-sm btn-outline-dark" href="{{ route('dashboard.students.invoices.create',$student) }}">{{ __('finance_uat.issue_invoice') }}</a>@endcan
        </div>
        {{-- Faster daily cashier workflow: every open/payable invoice is a
             direct button into the existing canonical payment form
             (dashboard.invoices.payments.create -> FinanceOperationsController::
             createPayment()/storePayment() -> InvoicePaymentService::record()),
             not just when there happens to be exactly one. No new payment
             engine, no bypass — same route, same validation, same service.

             Student Payment Allocation UX corrective (Section A) — each
             payable invoice now also lists its InvoiceItem services so the
             cashier can see WHAT the outstanding amount is made of before
             opening the payment form. Deliberately display-only: item
             names/amounts come straight from the already-eager-loaded
             invoices.items.fee relation (no new query per row), and the
             period label (if any) is read from metadata already stored on
             the item itself — never a live remaining-allocatable
             computation, which belongs on the single-invoice payment form
             (create.blade.php) where calling InvoicePaymentService once per
             page is cheap; doing that here, once per invoice per row of a
             paginated student list, would be a real N+1 query risk. This
             screen never performs a payment write. --}}
        @can('manage invoices')
            @if($payable->isNotEmpty())
                <div class="d-flex flex-column gap-2">
                    @foreach($payable as $invoice)
                        @php $items = $invoice->items; @endphp
                        <div class="border rounded p-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-semibold">{{ $invoice->display_number }}</span>
                                <span class="small text-muted">Остаток: <strong>{{ number_format((float) $invoice->remaining_amount, 2, '.', ' ') }} EGP</strong></span>
                            </div>
                            @if($items->isNotEmpty())
                                <ul class="list-unstyled small text-muted mb-2">
                                    @foreach($items as $item)
                                        <li class="d-flex justify-content-between gap-2">
                                            <span>{{ $item->fee?->name_ru ?? $item->description }}@if($period = \App\Support\InvoiceItemPeriodLabel::forItem($item))<span class="text-muted"> — {{ $period }}</span>@endif</span>
                                            <span>{{ number_format((float) $item->amount, 2, '.', ' ') }} EGP</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <a class="btn btn-sm btn-success w-100" href="{{ route('dashboard.invoices.payments.create',$invoice) }}">Оплатить</a>
                        </div>
                    @endforeach
                </div>
            @endif
        @endcan
    </td></tr>
    @empty<tr><td colspan="9" class="text-center py-4">Ученики не найдены.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $students->links() }}</div></div>
</div>
@endsection
