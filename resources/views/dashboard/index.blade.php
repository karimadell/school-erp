@extends('layouts.dashboard')

@section('content')

<div class="ui2-scope mx-auto max-w-7xl">

    <h1 class="mb-6 text-2xl font-semibold text-slate-900">{{ __('dashboard.title') }}</h1>

    {{-- ================= KPI Cards ================= --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <x-ui-icon name="banknote" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.total_income') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ number_format($totalIncome ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                    <x-ui-icon name="receipt" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.invoices') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ $invoicesCount ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
                    <x-ui-icon name="users" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.students') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ $studentsCount ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                    <x-ui-icon name="landmark" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.cash_transactions') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ $transactionsCount ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                    <x-ui-icon name="briefcase" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.cash_balance') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ number_format($cashBalance ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <x-ui-icon name="banknote" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.today_income') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ number_format($todayRevenue ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-600">
                    <x-ui-icon name="graduation_cap" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.teachers') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ $teachersCount ?? 0 }}</div>
                    <div class="truncate text-xs text-slate-400">
                        {{ __('dashboard.active_teachers') }}: {{ $activeTeachersCount ?? 0 }}
                        · {{ __('dashboard.inactive_teachers') }}: {{ $inactiveTeachersCount ?? 0 }}
                    </div>
                </div>
            </div>
        </div>

        <div class="ui2-card">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600">
                    <x-ui-icon name="school" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-slate-500">{{ __('dashboard.classes') }}</div>
                    <div class="text-xl font-semibold text-slate-900">{{ $classesCount ?? 0 }}</div>
                    <div class="truncate text-xs text-slate-400">{{ __('dashboard.subjects') }}: {{ $subjectsCount ?? 0 }}</div>
                </div>
            </div>
        </div>

    </div>

    {{-- ================= Charts (Chart.js, same data as before, restyled wrapper) ================= --}}
    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">

        <div class="ui2-card">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">
                <x-ui-icon name="receipt" class="h-4 w-4 text-slate-400" /> {{ __('dashboard.invoices_daily') }}
            </h2>
            <canvas id="invoiceChart"></canvas>
        </div>

        <div class="ui2-card">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">
                <x-ui-icon name="graduation_cap" class="h-4 w-4 text-slate-400" /> {{ __('dashboard.teachers_by_specialization') }}
            </h2>
            <canvas id="teachersSpecializationChart"></canvas>
        </div>

        <div class="ui2-card">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">
                <x-ui-icon name="activity" class="h-4 w-4 text-slate-400" /> {{ __('dashboard.teachers_status') }}
            </h2>
            <canvas id="teachersStatusChart"></canvas>
        </div>

        <div class="ui2-card">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">
                <x-ui-icon name="book_open" class="h-4 w-4 text-slate-400" /> {{ __('dashboard.top_teacher_subjects') }}
            </h2>
            <canvas id="topTeacherSubjectsChart"></canvas>
        </div>

    </div>

    {{-- ================= Latest payments / Upcoming exams ================= --}}
    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">

        <div class="ui2-card">
            <h2 class="mb-4 text-sm font-semibold text-slate-900">{{ __('dashboard.latest_payments') }}</h2>

            @if(($latestPayments ?? collect())->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">{{ __('dashboard.no_data') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                                <th class="py-2 text-start font-medium">{{ __('dashboard.category') }}</th>
                                <th class="py-2 text-start font-medium">{{ __('dashboard.amount') }}</th>
                                <th class="py-2 text-start font-medium">{{ __('app.date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $categoryLabels = [
                                    'income' => __('app.income'),
                                    'expense' => __('app.expenses'),
                                    'transfer' => __('app.transfers'),
                                ];
                            @endphp

                            @foreach($latestPayments as $payment)
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="py-2.5 text-slate-700">
                                        {{ $payment->description ?: ($categoryLabels[$payment->category] ?? '—') }}
                                    </td>
                                    <td class="py-2.5 font-medium {{ $payment->type === 'in' ? 'text-emerald-600' : 'text-red-600' }}">
                                        {{ $payment->type === 'in' ? '+' : '−' }}{{ number_format($payment->amount, 2) }}
                                    </td>
                                    <td class="py-2.5 text-slate-500">{{ $payment->created_at?->format('d.m.Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ui2-card">
            <h2 class="mb-4 text-sm font-semibold text-slate-900">{{ __('dashboard.upcoming_exams') }}</h2>

            @if(($upcomingExams ?? collect())->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">{{ __('dashboard.no_upcoming_exams') }}</p>
            @else
                <ul class="divide-y divide-slate-50">
                    @foreach($upcomingExams as $exam)
                        <li class="flex items-center justify-between py-2.5 text-sm">
                            <span class="truncate text-slate-700">{{ $exam->name }}</span>
                            <span class="shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($exam->exam_date)->format('d.m.Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    </div>

    {{-- ================= Attendance rate ================= --}}
    <div class="ui2-card mt-6">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                <x-ui-icon name="clipboard_list" class="h-5 w-5" />
            </span>
            <div>
                <div class="text-sm text-slate-500">{{ __('dashboard.attendance_rate') }}</div>
                <div class="text-xl font-semibold text-slate-900">{{ $attendanceRate ?? 0 }}%</div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const invoiceDaily = @json($invoiceDaily ?? []);
    const teachersBySpecialization = @json($teachersBySpecialization ?? []);
    const teachersStatusChart = @json($teachersStatusChart ?? []);
    const topTeacherSubjects = @json($topTeacherSubjects ?? []);

    function makeChart(id, type, labels, data, label) {
        const el = document.getElementById(id);
        if (!el) return;

        labels = Array.isArray(labels) ? labels : Object.keys(labels || {});
        data = Array.isArray(data) ? data : Object.values(data || {});

        new Chart(el, {
            type: type,
            data: {
                labels: labels,
                datasets: [{
                    label: label || '',
                    data: data,
                    borderWidth: 2
                }]
            }
        });
    }

    makeChart('invoiceChart', 'line', Object.keys(invoiceDaily), Object.values(invoiceDaily), @json(__('dashboard.invoices_daily')));
    makeChart('teachersSpecializationChart', 'bar', Object.keys(teachersBySpecialization), Object.values(teachersBySpecialization), @json(__('dashboard.teachers_by_specialization')));
    makeChart('teachersStatusChart', 'doughnut', Object.keys(teachersStatusChart), Object.values(teachersStatusChart), @json(__('dashboard.teachers_status')));
    makeChart('topTeacherSubjectsChart', 'bar', Object.keys(topTeacherSubjects), Object.values(topTeacherSubjects), @json(__('dashboard.top_teacher_subjects')));

});
</script>

@endsection
