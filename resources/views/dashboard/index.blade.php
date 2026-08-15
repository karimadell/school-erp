@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="mb-4 fw-bold">{{ __('dashboard.title') }}</h3>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="p-4 text-white rounded bg-success shadow-sm"><div>{{ __('dashboard.total_income') }}</div><div class="fs-3 fw-bold">{{ number_format($totalIncome ?? 0, 2) }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded bg-primary shadow-sm"><div>{{ __('dashboard.invoices') }}</div><div class="fs-3 fw-bold">{{ $invoicesCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-dark rounded bg-info shadow-sm"><div>{{ __('dashboard.students') }}</div><div class="fs-3 fw-bold">{{ $studentsCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded bg-secondary shadow-sm"><div>{{ __('dashboard.cash_transactions') }}</div><div class="fs-3 fw-bold">{{ $transactionsCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded shadow-sm" style="background:#8e44ad;"><div>{{ __('dashboard.teachers') }}</div><div class="fs-3 fw-bold">{{ $teachersCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded bg-success shadow-sm"><div>{{ __('dashboard.active_teachers') }}</div><div class="fs-3 fw-bold">{{ $activeTeachersCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded bg-danger shadow-sm"><div>{{ __('dashboard.inactive_teachers') }}</div><div class="fs-3 fw-bold">{{ $inactiveTeachersCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-dark rounded bg-warning shadow-sm"><div>{{ __('dashboard.classes') }}</div><div class="fs-3 fw-bold">{{ $classesCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded shadow-sm" style="background:#34495e;"><div>{{ __('dashboard.subjects') }}</div><div class="fs-3 fw-bold">{{ $subjectsCount ?? 0 }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded shadow-sm" style="background:#6f42c1;"><div>{{ __('dashboard.cash_balance') }}</div><div class="fs-3 fw-bold">{{ number_format($cashBalance ?? 0, 2) }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded shadow-sm" style="background:#198754;"><div>{{ __('dashboard.today_income') }}</div><div class="fs-3 fw-bold">{{ number_format($todayRevenue ?? 0, 2) }}</div></div></div>
        <div class="col-md-3"><div class="p-4 text-white rounded shadow-sm" style="background:#0d6efd;"><div>{{ __('dashboard.attendance_rate') }}</div><div class="fs-3 fw-bold">{{ $attendanceRate ?? 0 }}%</div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-header fw-bold">🧾 {{ __('dashboard.invoices_daily') }}</div><div class="card-body"><canvas id="invoiceChart"></canvas></div></div></div>
        <div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-header fw-bold">💰 {{ __('dashboard.cash_flow') }}</div><div class="card-body"><canvas id="cashChart"></canvas></div></div></div>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-header fw-bold">👨‍🏫 {{ __('dashboard.teachers_by_specialization') }}</div><div class="card-body"><canvas id="teachersSpecializationChart"></canvas></div></div></div>
        <div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-header fw-bold">⚡ {{ __('dashboard.teachers_status') }}</div><div class="card-body"><canvas id="teachersStatusChart"></canvas></div></div></div>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-md-12"><div class="card shadow-sm border-0"><div class="card-header fw-bold">📚 {{ __('dashboard.top_teacher_subjects') }}</div><div class="card-body"><canvas id="topTeacherSubjectsChart"></canvas></div></div></div>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header fw-bold">💵 {{ __('dashboard.latest_payments') }}</div>
                <div class="card-body p-0">
                    @if(($latestPayments ?? collect())->isEmpty())
                        <p class="text-center text-muted p-4 mb-0">{{ __('dashboard.no_data') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead><tr><th>{{ __('dashboard.category') }}</th><th>{{ __('dashboard.amount') }}</th><th>{{ __('app.date') }}</th></tr></thead>
                                <tbody>
                                @foreach($latestPayments as $payment)
                                    <tr>
                                        <td>{{ $payment->description ?: ($payment->category ?? '—') }}</td>
                                        <td class="fw-bold {{ $payment->type === 'in' ? 'text-success' : 'text-danger' }}">{{ $payment->type === 'in' ? '+' : '−' }}{{ number_format($payment->amount, 2) }}</td>
                                        <td>{{ $payment->created_at?->format('d.m.Y') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header fw-bold">📅 {{ __('dashboard.upcoming_exams') }}</div>
                <div class="card-body">
                    @if(($upcomingExams ?? collect())->isEmpty())
                        <p class="text-center text-muted p-4 mb-0">{{ __('dashboard.no_upcoming_exams') }}</p>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($upcomingExams as $exam)
                                <div class="list-group-item d-flex justify-content-between px-0"><span>{{ $exam->name }}</span><span class="text-muted">{{ \Illuminate\Support\Carbon::parse($exam->exam_date)->format('d.m.Y') }}</span></div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const invoiceDaily = @json($invoiceDaily ?? []);
    const cashDailyRaw = @json($cashDailyRaw ?? []);
    const teachersBySpecialization = @json($teachersBySpecialization ?? []);
    const teachersStatusChart = @json($teachersStatusChart ?? []);
    const topTeacherSubjects = @json($topTeacherSubjects ?? []);

    function makeChart(id, type, labels, data, label) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, { type, data: { labels, datasets: [{ label: label || '', data, borderWidth: 2 }] } });
    }

    makeChart('invoiceChart', 'line', Object.keys(invoiceDaily), Object.values(invoiceDaily), @json(__('dashboard.invoices_daily')));
    makeChart('teachersSpecializationChart', 'bar', Object.keys(teachersBySpecialization), Object.values(teachersBySpecialization), @json(__('dashboard.teachers_by_specialization')));
    makeChart('teachersStatusChart', 'doughnut', Object.keys(teachersStatusChart), Object.values(teachersStatusChart), @json(__('dashboard.teachers_status')));
    makeChart('topTeacherSubjectsChart', 'bar', Object.keys(topTeacherSubjects), Object.values(topTeacherSubjects), @json(__('dashboard.top_teacher_subjects')));

    const cashIn = Array.isArray(cashDailyRaw.in) ? cashDailyRaw.in : Object.values(cashDailyRaw.in || {});
    const cashOut = Array.isArray(cashDailyRaw.out) ? cashDailyRaw.out : Object.values(cashDailyRaw.out || {});
    const cashDates = [...new Set([...cashIn, ...cashOut].map(item => item.date))].sort();
    const cashValue = (rows, date) => Number((rows.find(item => item.date === date) || {}).total || 0);
    const cashCanvas = document.getElementById('cashChart');
    if (cashCanvas) {
        new Chart(cashCanvas, { type: 'line', data: { labels: cashDates, datasets: [
            { label: @json(__('app.income')), data: cashDates.map(date => cashValue(cashIn, date)), borderWidth: 2 },
            { label: @json(__('app.expenses')), data: cashDates.map(date => cashValue(cashOut, date)), borderWidth: 2 }
        ] } });
    }
});
</script>
@endsection
