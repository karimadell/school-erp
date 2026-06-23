@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">
                📋 {{ __('attendance.take_attendance') }}
            </h3>

            <small class="text-muted">
                {{ $class->name_ru ?? $class->code }}
                — {{ $date }}
                — {{ $type === 'daily' ? __('attendance.daily') : __('attendance.period') }}
            </small>
        </div>

        <a href="{{ route('dashboard.attendance.index') }}" class="btn btn-secondary">
            ← {{ __('attendance.back') }}
        </a>
    </div>

    {{-- Success --}}
    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.attendance.store') }}">
        @csrf

        <input type="hidden" name="class_id" value="{{ $class->id }}">
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="type" value="{{ $type }}">

        @if($type === 'period')
            <input type="hidden" name="period_id" value="{{ $periodId }}">
        @endif

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-bordered align-middle mb-0 attendance-table">

                        <thead class="table-light">
                            <tr>
                                <th width="60">#</th>
                                <th>{{ __('attendance.student') }}</th>
                                <th width="220">{{ __('attendance.status') }}</th>
                                <th>{{ __('attendance.note') }}</th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse($enrollments as $enrollment)
                                @php
                                    $old = $existing[$enrollment->id] ?? null;
                                @endphp

                                <tr>
                                    <td>{{ $loop->iteration }}</td>

                                    <td class="fw-bold">
                                        {{ $enrollment->student->name ?? $enrollment->student->full_name ?? '—' }}
                                    </td>

                                    <td>
                                        <select name="attendance[{{ $enrollment->id }}][status]"
                                                class="form-select status-select">

                                            <option value="present" @selected(($old->status ?? 'present') === 'present')>
                                                ✅ {{ __('attendance.present') }}
                                            </option>

                                            <option value="absent" @selected(($old->status ?? '') === 'absent')>
                                                ❌ {{ __('attendance.absent') }}
                                            </option>

                                            <option value="late" @selected(($old->status ?? '') === 'late')>
                                                ⏰ {{ __('attendance.late') }}
                                            </option>

                                            <option value="excused" @selected(($old->status ?? '') === 'excused')>
                                                📝 {{ __('attendance.excused') }}
                                            </option>

                                        </select>
                                    </td>

                                    <td>
                                        <input type="text"
                                               name="attendance[{{ $enrollment->id }}][note]"
                                               value="{{ $old->note ?? '' }}"
                                               class="form-control"
                                               placeholder="{{ __('attendance.note') }}">
                                    </td>
                                </tr>

                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted">
                                        {{ __('attendance.no_students') }}
                                    </td>
                                </tr>
                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>
        </div>

        <div class="mt-3">
            <button class="btn btn-success px-4">
                💾 {{ __('attendance.save') }}
            </button>
        </div>

    </form>

</div>

{{-- Styles --}}
<style>
.attendance-table td {
    vertical-align: middle;
}

.status-select {
    font-weight: 500;
}

.status-select option[value="present"] {
    color: green;
}

.status-select option[value="absent"] {
    color: red;
}

.status-select option[value="late"] {
    color: orange;
}
</style>

@endsection