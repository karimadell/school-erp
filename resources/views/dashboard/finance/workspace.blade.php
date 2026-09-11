@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><h1 class="h3">Финансовый центр</h1><p class="text-muted">Ежедневная работа со счетами и платежами</p></div>@can('manage invoices')<a class="btn btn-primary" href="{{ route('dashboard.invoices.create') }}">{{ __('finance_uat.issue_invoice') }}</a>@endcan</div>

    {{-- ================= A. Operational summary cards ================= --}}
    <div class="row g-3 mb-3">
        @foreach([
            __('finance_workspace.income_today') => ['value' => $operationalSummary['income_today'], 'accent' => 'text-success'],
            __('finance_workspace.expense_today') => ['value' => $operationalSummary['expense_today'], 'accent' => 'text-danger'],
            __('finance_workspace.net_today') => ['value' => $operationalSummary['net_today'], 'accent' => (float) $operationalSummary['net_today'] < 0 ? 'text-danger' : 'text-success'],
            __('finance_workspace.total_balance') => ['value' => $operationalSummary['total_balance'], 'accent' => ''],
        ] as $label => $card)
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">{{ $label }}</div>
                    <strong class="fs-5 {{ $card['accent'] }}">{{ number_format((float) $card['value'], 2, '.', ' ') }} EGP</strong>
                </div></div>
            </div>
        @endforeach
    </div>

    {{-- ================= B. Primary actions + C. secondary links ================= --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        @can('view invoices')
            <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-success btn-lg px-4">+ {{ __('finance_workspace.add_income') }}</a>
        @endcan
        @can('create', \App\Models\Expense::class)
            <a href="{{ route('dashboard.finance.expenses.create') }}" class="btn btn-danger btn-lg px-4">+ {{ __('finance_workspace.add_expense') }}</a>
        @endcan
        <div class="vr d-none d-md-block mx-2"></div>
        @can('manage expenses')
            <a href="{{ route('dashboard.finance.expenses.index') }}" class="btn btn-outline-secondary">{{ __('expenses.page_title') }}</a>
        @endcan
        @canany(['manage cash', 'transfer cash', 'view cash reports'])
            <a href="{{ route('dashboard.cash.operations.index') }}" class="btn btn-outline-secondary">{{ __('finance_workspace.cash') }}</a>
        @endcanany
        @can('view cash reports')
            <a href="{{ route('dashboard.cash.reports') }}" class="btn btn-outline-secondary">{{ __('finance_workspace.reports') }}</a>
        @endcan
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
<tr><td>{{ $student->full_name }}<div class="small text-muted">ID {{ $student->id }} · {{ $student->phone }}</div></td><td>{{ $student->currentEnrollment?->academicYear?->name ?: '—' }}</td><td>{{ $summary['invoiced'] }} EGP</td><td>{{ $summary['paid'] }} EGP</td><td>{{ $summary['remaining'] }} EGP</td><td>{{ $summary['overdue'] }} EGP</td><td>{{ $summary['invoices']->first()?->display_number ?: '—' }}</td><td>{{ $summary['latest_payment']?->payment_number ?: '—' }}</td><td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-secondary" href="{{ route('dashboard.students.show',$student) }}">Открыть профиль</a><a class="btn btn-sm btn-outline-primary" href="{{ route('dashboard.students.finance',$student) }}">{{ __('finance_uat.student_account') }}</a>@can('manage invoices')<a class="btn btn-sm btn-primary" href="{{ route('dashboard.students.invoices.create',$student) }}">{{ __('finance_uat.issue_invoice') }}</a>@if($payable->count()===1)<a class="btn btn-sm btn-success" href="{{ route('dashboard.invoices.payments.create',$payable->first()) }}">Принять оплату</a>@endif @endcan</div></td></tr>
    @empty<tr><td colspan="9" class="text-center py-4">Ученики не найдены.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $students->links() }}</div></div>
</div>
@endsection
