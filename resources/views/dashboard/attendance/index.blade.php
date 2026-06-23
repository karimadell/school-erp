@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">📋 {{ __('attendance.title') }}</h3>
            <small class="text-muted">{{ __('attendance.take_attendance') }}</small>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white fw-bold">
            ⚙ {{ __('attendance.select_options') }}
        </div>

        <div class="card-body p-4">

            <form method="GET" action="{{ route('dashboard.attendance.create') }}" class="row g-3">

                <div class="col-md-4">
                    <label class="form-label fw-semibold">{{ __('attendance.class') }}</label>
                    <select name="class_id" class="form-select" required>
                        <option value="">{{ __('attendance.select_class') }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}">
                                {{ $class->name_ru ?? $class->code }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">{{ __('attendance.date') }}</label>
                    <input type="date"
                           name="date"
                           value="{{ date('Y-m-d') }}"
                           class="form-control"
                           required>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">{{ __('attendance.type') }}</label>
                    <select name="type" id="attendanceType" class="form-select" required>
                        <option value="daily">📅 {{ __('attendance.daily') }}</option>
                        <option value="period">🕘 {{ __('attendance.period') }}</option>
                    </select>
                </div>

                <div class="col-md-2 d-none" id="periodBox">
                    <label class="form-label fw-semibold">{{ __('attendance.period') }}</label>
                    <select name="period_id" class="form-select">
                        <option value="">{{ __('attendance.select_period') }}</option>
                        @foreach(\App\Models\Period::orderBy('number')->get() as $period)
                            <option value="{{ $period->id }}">
                                {{ __('attendance.lesson') }} {{ $period->number }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-12 mt-4">
                    <button class="btn btn-primary px-4">
                        🚀 {{ __('attendance.start') }}
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<script>
document.getElementById('attendanceType').addEventListener('change', function () {
    const periodBox = document.getElementById('periodBox');
    periodBox.classList.toggle('d-none', this.value !== 'period');
});
</script>

@endsection