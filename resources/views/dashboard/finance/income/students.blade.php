@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">{{ __('finance_workspace.income_students_title') }}</h1>
            <p class="text-muted mb-0">{{ __('finance_workspace.income_students_hint') }}</p>
        </div>
        <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
    </div>

    {{-- Search is the dominant element on this page; secondary invoice-level
         filters are collapsed by default so they never compete with it. --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-9">
                        <label class="form-label small text-muted">Ученик</label>
                        <input class="form-control form-control-lg" name="q" value="{{ request('q') }}" placeholder="ФИО, телефон или ID">
                    </div>
                    <div class="col-lg-3 d-flex gap-2">
                        <button class="btn btn-primary btn-lg flex-fill">Найти</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#secondary-filters" aria-expanded="false">
                            Фильтры
                        </button>
                    </div>
                </div>

                <div class="collapse mt-3 @if(request()->anyFilled(['academic_year_id','status','date_from','date_to']) || request()->boolean('overdue')) show @endif" id="secondary-filters">
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Учебный год</label>
                            <select class="form-select" name="academic_year_id">
                                <option value="">Все</option>
                                @foreach($years as $year)
                                    <option value="{{ $year->id }}" @selected(request('academic_year_id') == $year->id)>{{ $year->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Статус счёта</label>
                            <select class="form-select" name="status">
                                <option value="">Все</option>
                                <option value="unpaid" @selected(request('status') === 'unpaid')>Не оплачен</option>
                                <option value="partial" @selected(request('status') === 'partial')>Частично оплачен</option>
                                <option value="paid" @selected(request('status') === 'paid')>Оплачен</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Счета с даты</label>
                            <input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Счета по дату</label>
                            <input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}">
                        </div>
                        <div class="col-12 d-flex align-items-center justify-content-between">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="overdue" value="1" id="overdue-only" @checked(request('overdue'))>
                                <label class="form-check-label" for="overdue-only">Только просроченные</label>
                            </div>
                            <a href="{{ route('dashboard.finance.income.students') }}" class="btn btn-sm btn-outline-secondary">Сбросить фильтры</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ученик</th>
                        <th>Класс / год</th>
                        <th>Начислено</th>
                        <th>Остаток</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $row)
                        @php
                            $student = $row['student'];
                            $summary = $row['summary'];
                            $overdueAmount = (float) $summary['overdue'];
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $student->full_name }}</div>
                                <div class="text-muted small">ID {{ $student->id }} · {{ $student->phone }}</div>
                            </td>
                            <td class="text-muted small">
                                {{ $student->currentEnrollment?->schoolClass?->name ?: '—' }}
                                @if($student->currentEnrollment?->academicYear)
                                    <div>{{ $student->currentEnrollment->academicYear->name }}</div>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $summary['invoiced'] }} EGP</td>
                            <td>
                                <div class="fw-semibold">{{ $summary['remaining'] }} EGP</div>
                                @if($overdueAmount > 0)
                                    <span class="badge bg-danger">Просрочено: {{ $summary['overdue'] }} EGP</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @can('manage invoices')
                                        <a class="btn btn-sm btn-primary" href="{{ route('dashboard.students.add-service', $student) }}">{{ __('finance_uat.add_service') }}</a>
                                        <a class="btn btn-sm btn-outline-success" href="{{ route('dashboard.students.stolovaya.create', $student) }}">{{ __('finance_workspace.stolovaya_row_action') }}</a>
                                    @endcan
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('dashboard.students.finance', $student) }}">{{ __('finance_uat.student_account') }}</a>
                                    @can('manage invoices')
                                        {{-- Unified Cashier Workspace (PR C1) — replaces the old
                                             conditional single-invoice/finance-page fallback: the
                                             workspace already shows every outstanding obligation
                                             plus lets the operator add a new service, so it fully
                                             subsumes both previous destinations. --}}
                                        <a class="btn btn-sm btn-success" href="{{ route('dashboard.students.unified-collection.create', $student) }}">Принять оплату</a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Ученики не найдены.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $students->links() }}</div>
    </div>

</div>
@endsection
