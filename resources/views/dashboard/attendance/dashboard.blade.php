@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <h3 class="fw-bold mb-4">
        📈 {{ __('attendance.reports') }}
    </h3>

    {{-- ================= Chart ================= --}}
    <div class="card shadow-sm border-0 mb-4">

        <div class="card-header bg-dark text-white fw-bold">
            📈 {{ __('attendance.reports') }}
        </div>

        <div class="card-body">
            <canvas id="attendanceChart"></canvas>
        </div>

    </div>

    {{-- ================= Table ================= --}}
    <div class="card shadow-sm border-0">
        <div class="card-header fw-bold bg-dark text-white">
            {{ __('attendance.records') }}
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('attendance.date') }}</th>
                        <th>✅ {{ __('attendance.present') }}</th>
                        <th>❌ {{ __('attendance.absent') }}</th>
                        <th>⏰ {{ __('attendance.late') }}</th>
                        <th>📝 {{ __('attendance.excused') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($data as $row)
                        <tr>
                            <td>{{ $row->day }}</td>
                            <td>{{ $row->present }}</td>
                            <td>{{ $row->absent }}</td>
                            <td>{{ $row->late }}</td>
                            <td>{{ $row->excused }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted py-4">
                                {{ __('attendance.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- ================= Chart Script ================= --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const attendanceDays = @json($data->pluck('day'));
    const attendancePresent = @json($data->pluck('present'));
    const attendanceAbsent = @json($data->pluck('absent'));
    const attendanceLate = @json($data->pluck('late'));
    const attendanceExcused = @json($data->pluck('excused'));

    new Chart(document.getElementById('attendanceChart'), {
        type: 'bar',
        data: {
            labels: attendanceDays,
            datasets: [
                {
                    label: @json(__('attendance.present')),
                    data: attendancePresent,
                    backgroundColor: '#198754'
                },
                {
                    label: @json(__('attendance.absent')),
                    data: attendanceAbsent,
                    backgroundColor: '#dc3545'
                },
                {
                    label: @json(__('attendance.late')),
                    data: attendanceLate,
                    backgroundColor: '#ffc107'
                },
                {
                    label: @json(__('attendance.excused')),
                    data: attendanceExcused,
                    backgroundColor: '#0dcaf0'
                }
            ]
        }
    });
</script>

@endsection
