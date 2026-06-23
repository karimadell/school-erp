@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <h3 class="mb-4 fw-bold">{{ __('dashboard.title') }}</h3>

    {{-- ================= KPI Cards ================= --}}
    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-success shadow-sm">
                <div>{{ __('dashboard.total_income') }}</div>
                <div class="fs-3 fw-bold">{{ number_format($totalIncome ?? 0, 2) }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-primary shadow-sm">
                <div>{{ __('dashboard.invoices') }}</div>
                <div class="fs-3 fw-bold">{{ $invoicesCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-dark rounded bg-info shadow-sm">
                <div>{{ __('dashboard.students') }}</div>
                <div class="fs-3 fw-bold">{{ $studentsCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-secondary shadow-sm">
                <div>{{ __('dashboard.cash_transactions') }}</div>
                <div class="fs-3 fw-bold">{{ $transactionsCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded" style="background:#8e44ad;">
                <div>{{ __('dashboard.teachers') }}</div>
                <div class="fs-3 fw-bold">{{ $teachersCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-success">
                <div>{{ __('dashboard.active_teachers') }}</div>
                <div class="fs-3 fw-bold">{{ $activeTeachersCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-danger">
                <div>{{ __('dashboard.inactive_teachers') }}</div>
                <div class="fs-3 fw-bold">{{ $inactiveTeachersCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded bg-warning">
                <div>{{ __('dashboard.classes') }}</div>
                <div class="fs-3 fw-bold">{{ $classesCount ?? 0 }}</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-4 text-white rounded" style="background:#34495e;">
                <div>{{ __('dashboard.subjects') }}</div>
                <div class="fs-3 fw-bold">{{ $subjectsCount ?? 0 }}</div>
            </div>
        </div>

    </div>

    {{-- ================= Charts ================= --}}
    <div class="row g-4">

        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">🧾 {{ __('dashboard.invoices_daily') }}</div>
                <div class="card-body">
                    <canvas id="invoiceChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">💰 {{ __('dashboard.cash_flow') }}</div>
                <div class="card-body">
                    <canvas id="cashChart"></canvas>
                </div>
            </div>
        </div>

    </div>

    {{-- ================= Teachers Charts ================= --}}
    <div class="row g-4 mt-2">

        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">
                    👨‍🏫 {{ __('dashboard.teachers_by_specialization') }}
                </div>
                <div class="card-body">
                    <canvas id="teachersSpecializationChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">
                    ⚡ {{ __('dashboard.teachers_status') }}
                </div>
                <div class="card-body">
                    <canvas id="teachersStatusChart"></canvas>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4 mt-2">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">
                    📚 {{ __('dashboard.top_teacher_subjects') }}
                </div>
                <div class="card-body">
                    <canvas id="topTeacherSubjectsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const invoiceDaily = @json($invoiceDaily ?? []);
    const teachersBySpecialization = @json($teachersBySpecialization ?? []);
    const teachersStatusChart = @json($teachersStatusChart ?? []);
    const topTeacherSubjects = @json($topTeacherSubjects ?? []);

    function makeChart(id, type, labels, data) {
        const el = document.getElementById(id);
        if (!el) return;

        new Chart(el, {
            type: type,
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    borderWidth: 2
                }]
            }
        });
    }

    makeChart('invoiceChart','line',Object.keys(invoiceDaily),Object.values(invoiceDaily));
    makeChart('teachersSpecializationChart','bar',Object.keys(teachersBySpecialization),Object.values(teachersBySpecialization));
    makeChart('teachersStatusChart','doughnut',Object.keys(teachersStatusChart),Object.values(teachersStatusChart));
    makeChart('topTeacherSubjectsChart','bar',Object.keys(topTeacherSubjects),Object.values(topTeacherSubjects));

});
</script>

@endsection