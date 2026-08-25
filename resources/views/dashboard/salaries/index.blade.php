@extends('layouts.dashboard')

{{--
    Read-only dashboard-native mirror of
    App\Filament\Resources\TeacherSalaries\TeacherSalaryResource's list.
    Displays only persisted values (base_salary/bonus/allowances/deductions/
    net_salary are all written by TeacherSalary::calculateNet() /
    EmployeePayrollService) — this view never computes payroll totals
    itself. Approve/pay post to SalaryController, which calls
    EmployeePayrollService directly (no logic of its own) so the user
    stays in the unified shell; create/edit still link out to Filament,
    which remains the sole place those two forms exist.
--}}

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">💰 {{ __('teacher_salary.navigation') }}</h3>
            <small class="text-muted">{{ __('teacher_salary.model') }}</small>
        </div>

        @can('manage payroll')
            <a href="{{ route('dashboard.salaries.create') }}" class="btn btn-primary">
                + {{ __('teacher_salary.navigation') }}
            </a>
        @endcan
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('teacher_salary.employee') }}</th>
                            <th>{{ __('teacher_salary.position') }}</th>
                            <th class="text-end">{{ __('teacher_salary.base_salary') }}</th>
                            <th class="text-end">{{ __('teacher_salary.bonuses') }}</th>
                            <th class="text-end">{{ __('teacher_salary.allowances') }}</th>
                            <th class="text-end">{{ __('teacher_salary.deductions') }}</th>
                            <th class="text-end">{{ __('teacher_salary.net_salary') }}</th>
                            <th>{{ __('teacher_salary.month') }}</th>
                            <th>{{ __('teacher_salary.status') }}</th>
                            <th class="text-end">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($salaries as $salary)
                            <tr>
                                <td>{{ $salary->employee_display_name }}</td>
                                <td>{{ $salary->position ?? '—' }}</td>
                                <td class="text-end">{{ number_format($salary->base_salary, 2) }}</td>
                                <td class="text-end">{{ number_format($salary->bonus, 2) }}</td>
                                <td class="text-end">{{ number_format($salary->allowances, 2) }}</td>
                                <td class="text-end">{{ number_format($salary->deductions, 2) }}</td>
                                <td class="text-end fw-bold">{{ number_format($salary->net_salary, 2) }}</td>
                                <td>{{ $salary->salary_month->format('m.Y') }}</td>
                                <td>
                                    @php
                                        $statusColor = match ($salary->status) {
                                            \App\Models\TeacherSalary::STATUS_PAID => 'success',
                                            \App\Models\TeacherSalary::STATUS_APPROVED => 'primary',
                                            default => 'secondary',
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $statusColor }}">
                                        {{ __('teacher_salary.statuses.'.$salary->status) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        @can('manage payroll')
                                            @if($salary->status === \App\Models\TeacherSalary::STATUS_DRAFT)
                                                <a href="{{ \App\Filament\Resources\TeacherSalaries\TeacherSalaryResource::getUrl('edit', ['record' => $salary]) }}"
                                                   class="btn btn-sm btn-outline-secondary">
                                                    {{ __('app.edit') }}
                                                </a>
                                            @endif
                                        @endcan

                                        {{-- Both post to SalaryController, which calls
                                             EmployeePayrollService directly — same call the
                                             Filament table action makes, just triggered from
                                             here instead, so the shell never swaps out. --}}
                                        @can('approve payroll')
                                            @if($salary->status === \App\Models\TeacherSalary::STATUS_DRAFT)
                                                <form method="POST" action="{{ route('dashboard.salaries.approve', $salary) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                                            onclick="return confirm('{{ __('teacher_salary.approve') }}?')">
                                                        {{ __('teacher_salary.approve') }}
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                        @can('pay payroll')
                                            @if($salary->status === \App\Models\TeacherSalary::STATUS_APPROVED)
                                                <a href="{{ route('dashboard.salaries.pay.create', $salary) }}"
                                                   class="btn btn-sm btn-outline-success">
                                                    {{ __('teacher_salary.pay') }}
                                                </a>
                                            @endif
                                        @endcan

                                        <a href="{{ route('dashboard.teacher-salaries.print', $salary) }}"
                                           class="btn btn-sm btn-outline-dark" target="_blank">
                                            {{ __('teacher_salary.print') }}
                                        </a>
                                        <a href="{{ route('dashboard.teacher-salaries.pdf', $salary) }}"
                                           class="btn btn-sm btn-outline-danger">
                                            {{ __('teacher_salary.pdf') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    {{ __('teacher_salary.empty_heading') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $salaries->links() }}</div>
</div>
@endsection
